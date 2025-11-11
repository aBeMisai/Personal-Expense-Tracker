# -*- coding: utf-8 -*-
import sys, os, json, re
from datetime import datetime
from paddleocr import PaddleOCR
import cv2
import numpy as np
from categorizer import ReceiptCategorizer



# ---------- brand lexicon & helpers ----------
BRAND_REGEXES = [
    (r"\bstarbucks?\b|frappuccino|venti|grande|macchiato", "Starbucks"),
    (r"\bnike\b|nike\.com|just\s*do\s*it", "Nike"),
    (r"\badidas\b|adidas\.com", "Adidas"),
    (r"\buniqlo\b|uniqlo\.com", "UNIQLO"),
    (r"\b7[- ]?eleven\b|7eleven", "7-Eleven"),
    (r"\bikea\b|ikea\.com", "IKEA"),
    (r"\bsephora\b", "Sephora"),
    (r"\bwatsons\b", "Watsons"),
    (r"\bguardian\b", "Guardian"),
    (r"\bpetronas\b", "Petronas"),
    (r"\bshell\b", "Shell"),
    (r"\bmr\.?\s*diy\b|mr[ -]?diy", "MR DIY"),
    (r"\bdomino'?s\b", "Domino's"),
    (r"\bpizza\s*h?ut\b", "Pizza Hut"),
    (r"\bmc\s?donald'?s\b|mcd\b", "McDonald's"),
    (r"\bsubway\b", "Subway"),
    (r"\b99\s*speed\s*mart\b|99\s*speedmart|99speedmart", "99 Speedmart"),
    (r"\bfamily\s*mart\b|familymart", "FamilyMart"),
    (r"\bfamily\s*hart\b|familyhart", "FamilyMart"),
    (r"\bbungkus\s+ikat\s+tepi\b", "Bungkus Ikat Tepi"),
    (r"\bcoriander\s*&\s*coffee\b|c\s*&\s*c\b", "Coriander & Coffee"),
    (r"\brosto\b", "Rosto"),
]

MERCHANT_CATEGORY_HINTS = {
    "99 speedmart": "Food & Dining",
    "speed mart": "Food & Dining",
    "familymart": "Food & Dining",
    "family mart": "Food & Dining",
    "bungkus ikat tepi": "Food & Dining",
    "coriander": "Food & Dining",
    "rosto": "Food & Dining",
    "petronas": "Transportation",
    "primax": "Transportation",
    "shell": "Transportation",
}

CURRENCY_RE = r"(?:RM|MYR|USD|SGD|GBP|EUR|\$|\u00a3|\u20ac)?"
MONEY_CAPTURE = rf"{CURRENCY_RE}\s*(\d{{1,3}}(?:[.,]\d{{3}})+(?:[.,]\d{{1,2}})?|\d+(?:[.,]\d{{1,2}})?)"
MONEY_TIGHT = re.compile(MONEY_CAPTURE)

MAX_PLAUSIBLE_AMOUNT = 100000.0

def _is_plausible_money(value: float) -> bool:
    return value is not None and value > 0 and value <= MAX_PLAUSIBLE_AMOUNT

DATE_LIKE = re.compile(r"\d{1,2}[-/]\d{1,2}[-/]\d{2,4}")
TIME_LIKE = re.compile(r"\d{1,2}:\d{2}")
TRAILING_DOT_PRICE = re.compile(r"^\s*\d+[.,]\s*$")

def _norm_money(s: str) -> float:
    # remove currency, normalize separators; handle "38,02" vs "38.02"
    s = s.strip()
    s = re.sub(r"(RM|MYR|USD|SGD|GBP|EUR|\$|\u00a3|\u20ac)", "", s, flags=re.I).strip()
    # if exactly one comma and no dot => decimal comma
    if s.count(",") == 1 and s.count(".") == 0:
        s = s.replace(",", ".")
    # otherwise commas are thousands separators
    if s.count(".") == 1:
        s = s.replace(",", "")
    return float(s)

def _looks_like_line(s: str) -> bool:
    s = s.strip()
    if not s:
        return False
    # keep currency/amount-only lines
    if MONEY_TIGHT.search(s):
        return True
    # keep lines that contain at least 2 digits (e.g., "192.10", "170")
    if sum(ch.isdigit() for ch in s) >= 2:
        return True
    # otherwise require some letters so we skip noise
    letters = sum(ch.isalpha() for ch in s)
    return len(s) >= 3 and letters >= 1


def _flatten_text(ocr_out):
    """Turn PaddleOCR output (any version) into text lines."""
    lines = []

    def walk(o):
        if isinstance(o, (list, tuple)):
            for it in o:
                if (
                    isinstance(it, (list, tuple)) and len(it) >= 2
                    and isinstance(it[1], (list, tuple)) and it[1]
                    and isinstance(it[1][0], str)
                ):
                    t = it[1][0].strip()
                    if _looks_like_line(t):
                        lines.append(t)
                else:
                    walk(it)
        elif isinstance(o, dict):
            for k in ("text", "transcription", "value", "content", "sentence"):
                v = o.get(k)
                if isinstance(v, str) and _looks_like_line(v):
                    lines.append(v.strip())
            for v in o.values():
                walk(v)
        elif isinstance(o, str):
            if _looks_like_line(o):
                lines.append(o.strip())

    walk(ocr_out)
    # de-dup while preserving order
    seen, out = set(), []
    for ln in lines:
        key = (ln.lower(), len(ln))
        if key not in seen:
            seen.add(key)
            out.append(ln)
    return out

# ---------- line-item helpers ----------
ITEM_NOISE = re.compile(
    r"\b(total|grand\s*total|cash|change|invoice|amount|amt|aot|qty|quantity|item(s)?|desc|"
    r"no\.?\s*of|visit|url|request|e-?invoice|date|time|balance|due|amount\(rm\))\b",
    re.I
)
QTY_LINE = re.compile(rf"^\s*(\d+)\s*[x\u00d7]\s*{MONEY_CAPTURE}\s*$", re.I)
INLINE_PRICE = re.compile(rf"^(.*?)[\s\.]+{MONEY_CAPTURE}\s*$")
SKU_PREFIX = re.compile(r"^\s*\d{3,8}\s+")  # drop leading item codes like "3645 "
# price forms that appear without description
X_PRICE     = re.compile(rf"^[x\u00d7]\s*{MONEY_CAPTURE}\s*$", re.I)     # "x 1.35" | "x1.35"
PRICE_ONLY  = re.compile(rf"^\s*{MONEY_CAPTURE}\s*$")               # "29.90"
SUMMARY_NEAR = re.compile(
    r"(tota[l]?|grand\s*total|sub\s*total|cash\w*|change\w*|amount\s*\(rm\)|amount\s*due|balance\s*due|paid)",
    re.I
)



def clean_desc(s: str) -> str:
    s = SKU_PREFIX.sub("", s).strip()
    # collapse multiple spaces & remove trailing punctuation
    s = re.sub(r"\s{2,}", " ", s)
    s = re.sub(r"[:\-\u2022]+$", "", s)
    return s.title()

def parse_line_items(lines, categorizer, receipt_total=None, merchant=None):
    items = []
    pending_desc = None

    merchant_norm = (merchant or "").strip().lower()

    # words that strongly indicate header/merchant lines, not items
    HEADER_NOISE = re.compile(
        r"\b(ssm|company|co\.?|s(?:dn)?\s*bhd|enterprise|tel\.?|phone|employee|cashier|pos|table|order|waiter|pax|number|time|product|qty|total?|amount|invoice|receipt|thank|powered\s*by|take\s*out|dine\s*in)\b",
        re.I
    )

    def is_valid_desc(text: str) -> bool:
        if not text:
            return False
        t = text.strip()
        if not t:
            return False
        low = t.lower()
        # require at least a couple of alphabetic characters so we skip lines like "1 X"
        if sum(ch.isalpha() for ch in t) < 2:
            return False
        # do not use merchant name (or close variants) as an item description
        if merchant_norm and len(merchant_norm) >= 4 and (low == merchant_norm or merchant_norm in low):
            return False
        if HEADER_NOISE.search(low):
            return False
        return True

    def find_prev_desc(idx):
        fragments = []
        for back in range(1, 6):
            j = idx - back
            if j < 0:
                break
            cand = lines[j].strip()
            if not cand:
                if fragments:
                    break
                continue
            if ITEM_NOISE.search(cand) or MONEY_TIGHT.search(cand):
                if fragments:
                    break
                continue
            if not is_valid_desc(cand):
                if fragments:
                    break
                continue
            fragments.append(cand)
        if fragments:
            return " ".join(reversed(fragments))
        return None

    def add_item(qty, desc, unit):
        if not desc:
            return
        if not _is_plausible_money(unit):
            return
        # validate description (avoid merchant/header lines)
        if not is_valid_desc(desc):
            return
        desc = clean_desc(desc)
        total = f"{qty * unit:.2f}"
        # de-dup: same desc + same total within the current list
        key = (desc.lower(), total)
        if any((it["desc"].lower(), it["total"]) == key for it in items):
            return
        cat = categorizer.predict(desc)
        items.append({
            "qty": qty,
            "desc": desc,
            "unit_price": f"{unit:.2f}",
            "total": total,
            "category": cat
        })

    for i, ln in enumerate(lines):
        ln = ln.strip()
        if not ln or ITEM_NOISE.search(ln):
            continue

        # 1) "1 x 4.95" -> pair with previous/pending description
        m = QTY_LINE.match(ln)
        if m:
            qty = int(m.group(1))
            unit = _norm_money(m.group(2))
            if not _is_plausible_money(unit):
                pending_desc = None
                continue
            desc = pending_desc or find_prev_desc(i)
            add_item(qty, desc, unit)
            pending_desc = None
            continue

        # 2) "DESC .... 1.35"
        m2 = INLINE_PRICE.match(ln)
        if m2 and sum(c.isalpha() for c in m2.group(1)) >= 4:
            # If the *next* line is "1 x 1.35", let that one consume the desc to avoid a duplicate
            if i + 1 < len(lines):
                mnext = QTY_LINE.match(lines[i + 1] or "")
                if mnext and abs(_norm_money(mnext.group(2)) - _norm_money(m2.group(2))) < 0.005:
                    pending_desc = m2.group(1)  # remember the desc for the next line
                    continue
            add_item(1, m2.group(1), _norm_money(m2.group(2)))
            pending_desc = None
            continue

        # 3) "x 1.35" -> assume qty=1, pair with previous/pending desc
        mx = X_PRICE.match(ln)
        if mx:
            add_item(1, pending_desc or find_prev_desc(i), _norm_money(mx.group(1)))
            pending_desc = None
            continue

        # 4) "29.90" alone -> skip if it’s a summary/total/cash/change amount
        raw_amount = None
        m3 = PRICE_ONLY.match(ln)
        if m3:
            raw_amount = m3.group(1)
        elif TRAILING_DOT_PRICE.match(ln):
            raw_amount = ln.strip()
        if raw_amount:
            if DATE_LIKE.search(ln) or TIME_LIKE.search(ln):
                continue
            price = _norm_money(raw_amount)
            if not _is_plausible_money(price):
                pending_desc = None
                continue
            prev = lines[i - 1] if i > 0 else ""
            nxt  = lines[i + 1] if i + 1 < len(lines) else ""
            desc_candidate = pending_desc or find_prev_desc(i)
            if pending_desc:
                combo = find_prev_desc(i)
                if combo and len(combo) > len(str(pending_desc)):
                    desc_candidate = combo
            # Skip clear summary sections (e.g., subtotal/total rows)
            if (SUMMARY_NEAR.search(prev) or SUMMARY_NEAR.search(nxt)) and not desc_candidate:
                pending_desc = None
                continue
            # If the amount exactly matches overall total AND we have no description,
            # treat it as the summary total instead of an item row.
            if (receipt_total and abs(price - float(receipt_total)) < 0.01) and not desc_candidate:
                pending_desc = None
                continue
            add_item(1, desc_candidate, price)
            pending_desc = None
            continue

        # 5) potential description waiting for a price line
        if sum(ch.isalpha() for ch in ln) >= 3 and not MONEY_TIGHT.search(ln):
            if is_valid_desc(ln):
                if pending_desc:
                    pending_desc = f"{pending_desc} {ln}".strip()
                else:
                    pending_desc = ln

    return items



# ---------- field parsing ----------
TOTAL_ALIASES = re.compile(
    r"(?<!sub)\b(total|grand\s*total|amount\s*due|balance\s*due)\b", re.I
)

def _pick_total(lines):

    amount = None
    last_idx = -1
    for i, ln in enumerate(lines):
        if TOTAL_ALIASES.search(ln):
            last_idx = i

    if last_idx >= 0:
        for j in range(0, 3):
            k = last_idx + j
            if k < len(lines):
                m = MONEY_TIGHT.search(lines[k])
                if m:
                    try:
                        val = _norm_money(m.group(1))
                        if _is_plausible_money(val):
                            amount = val
                            break
                    except Exception:
                        pass


   # Fallback: biggest number
    if amount is None:
        candidates = []
        for ln in lines:
            if DATE_LIKE.search(ln) or TIME_LIKE.search(ln):
                continue
            for m in MONEY_TIGHT.finditer(ln):
                try:
                    val = _norm_money(m.group(1))
                    if _is_plausible_money(val):
                        candidates.append(val)
                except:
                    pass
        if candidates:
            amount = max(candidates)

    # Extra fallback for lines with currency symbols or spaced decimals
    if amount is None:
        merged_lines = [re.sub(r"(\d)\s*[.,]\s*(\d{2})", r"\1.\2", ln) for ln in lines]
        for ln in merged_lines:
            if DATE_LIKE.search(ln) or TIME_LIKE.search(ln):
                continue
            m = MONEY_TIGHT.search(ln)
            if m:
                try:
                    val = _norm_money(m.group(1))
                    if _is_plausible_money(val):
                        amount = val
                        break
                except:
                    pass

    return f"{amount:.2f}" if amount is not None else ""



DATE_PATTERNS = [
    # 5/25/2025 or 05-25-2025 or 5/25/25
    (re.compile(r"\b(\d{1,2})[/-](\d{1,2})[/-](\d{2,4})\b"), "mdy"),
    # 25/05/2025 or 25-05-25
    (re.compile(r"\b(\d{1,2})[.-](\d{1,2})[.-](\d{2,4})\b"), "dmy"),
    # 2025/05/25
    (re.compile(r"\b(20\d{2})[/-](\d{1,2})[/-](\d{1,2})\b"), "ymd"),
    # May 25, 2025 / 25 May 2025
    (re.compile(r"\b([A-Za-z]{3,9})\s+(\d{1,2})(?:,)?\s+(20\d{2})\b"), "mon_d_y"),
    (re.compile(r"\b(\d{1,2})\s+([A-Za-z]{3,9})\s+(20\d{2})\b"), "d_mon_y"),
]

MONTHS = {
    "jan":1,"january":1,"feb":2,"february":2,"mar":3,"march":3,"apr":4,"april":4,
    "may":5,"jun":6,"june":6,"jul":7,"july":7,"aug":8,"august":8,
    "sep":9,"sept":9,"september":9,"oct":10,"october":10,"nov":11,"november":11,"dec":12,"december":12
}

def _parse_date(text):
    text = text.replace(",", " ")
    # scan bottom part first (most receipts put date/time there)
    lines = text.splitlines()
    search_space = "\n".join(lines[len(lines)//2:]) + "\n" + text

    for rx, kind in DATE_PATTERNS:
        m = rx.search(search_space)
        if not m:
            continue
        try:
            if kind == "mdy":
                a, b, c = m.groups()
                mm, dd, yy = int(a), int(b), int(c)
                if yy < 100: yy += 2000
                # if ambiguous (<=12 both), prefer DMY when currency looks Malaysian
                if mm <= 12 and dd <= 12:
                    if re.search(r"\b(RM|MYR)\b", text, flags=re.I):
                        mm, dd = dd, mm
                dt = datetime(yy, mm, dd)
            elif kind == "dmy":
                a, b, c = m.groups()
                dd, mm, yy = int(a), int(b), int(c)
                if yy < 100: yy += 2000
                dt = datetime(yy, mm, dd)
            elif kind == "ymd":
                yy, mm, dd = map(int, m.groups())
                dt = datetime(yy, mm, dd)
            elif kind == "mon_d_y":
                mon, dd, yy = m.groups()
                mm = MONTHS[mon.lower()]
                dt = datetime(int(yy), mm, int(dd))
            elif kind == "d_mon_y":
                dd, mon, yy = m.groups()
                mm = MONTHS[mon.lower()]
                dt = datetime(int(yy), mm, int(dd))
            else:
                continue
            return dt.strftime("%Y-%m-%d")
        except Exception:
            continue
    return ""

def _detect_brand(joined_text, top_lines):
    low = joined_text.lower()
    low = re.sub(r"[a-z]:\\\\[^\n]+", "", low)  # remove paths like C:\...

    # 1) explicit brand patterns anywhere
    for rx, name in BRAND_REGEXES:
        if re.search(rx, low, flags=re.I):
            return name

    # 2) first meaningful top line (if it looks like a venue name)
    skip = r"(phone|tel|gst|vat|store|slip|staff|date|table|qty|card|visa|debit|credit|subtotal|tax|total|welcome)"
    for ln in top_lines[:12]:
        if re.search(skip, ln, flags=re.I):
            continue
        if sum(c.isalpha() for c in ln) >= 3:
            # Title-case this guess
            guess = re.sub(r"[:\-\u2022]+$", "", ln.strip())
            if len(guess) < 4:
                continue
            if guess.islower():
                continue
            return guess

    return ""

def _debug_numbers(lines):
    print(f"DEBUG: line_count={len(lines)}", file=sys.stderr, flush=True)

    nums = []
    for ln in lines:
        for m in MONEY_TIGHT.finditer(ln):
            nums.append(m.group(0))
    # Print to STDERR so it doesn't break JSON output
    print("DEBUG: numbers found =", nums, file=sys.stderr)


def parse_fields(lines):
    joined = "\n".join(lines)
    # Remove any accidental path text
    joined = re.sub(r"[a-z]:\\[^\n]+", "", joined, flags=re.I)

    path_line = re.compile(r"^[a-z]:\\", re.I)
    lines = [ln for ln in lines if not path_line.match(ln.strip())]

    amount = _pick_total(lines)
    date   = _parse_date(joined)
    merchant = _detect_brand(joined, lines)

    # Use categorizer for item-level only
    from categorizer import ReceiptCategorizer
    categorizer = ReceiptCategorizer()

    items = parse_line_items(
        lines,
        categorizer,
        receipt_total=float(amount) if amount else None,
        merchant=merchant
    )

    merchant_hint = (merchant or "").lower()
    applied_hint = None
    for key, cat in MERCHANT_CATEGORY_HINTS.items():
        if key in merchant_hint:
            applied_hint = cat
            for item in items:
                if not item.get("category") or item["category"] == "Other":
                    item["category"] = cat
            break

    items_total = 0.0
    for item in items:
        try:
            items_total += float(item.get("total") or 0)
        except Exception:
            pass

    if not items and amount and applied_hint:
        try:
            amount_str = f"{float(str(amount).strip()):.2f}"
        except Exception:
            amount_str = str(amount)
        items.append({
            "qty": 1,
            # Prefer a neutral fallback rather than merchant name
            "desc": "Receipt",
            "unit_price": amount_str,
            "total": amount_str,
            "category": applied_hint
        })

    if items_total > 0:
        amount_val = float(amount) if amount else 0.0
        if amount_val <= 0 or abs(items_total - amount_val) > 0.01:
            amount = f"{items_total:.2f}"

    return {
        "merchant": merchant,
        "amount": amount,
        "date": date,
        "items": items,
        "raw_text": joined
    }


def preprocess_image(img_path):
    img = cv2.imread(img_path, cv2.IMREAD_COLOR)
    if img is None:
        return img_path

    # Convert to gray and upscale first (tiny text needs pixels)
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    h, w = gray.shape
    if w < 1200:
        scale = max(2, int(1200 / max(1, w)))
        gray = cv2.resize(gray, (w*scale, h*scale), interpolation=cv2.INTER_CUBIC)

    # Light deskew via minAreaRect on edges (helps small tilt)
    try:
        edges = cv2.Canny(gray, 50, 150)
        ys, xs = np.where(edges > 0)
        if len(xs) > 100:
            coords = np.column_stack((xs, ys)).astype(np.float32)
            rect = cv2.minAreaRect(coords)
            ang = rect[-1]
            ang = -(90 + ang) if ang < -45 else -ang
            if 0.5 <= abs(ang) <= 8.0:
                M = cv2.getRotationMatrix2D((gray.shape[1]//2, gray.shape[0]//2), ang, 1.0)
                gray = cv2.warpAffine(gray, M, (gray.shape[1], gray.shape[0]), flags=cv2.INTER_CUBIC, borderMode=cv2.BORDER_REPLICATE)
    except Exception:
        pass

    # Candidate A: CLAHE (great for faint thermal/purple ink)
    clahe = cv2.createCLAHE(clipLimit=3.0, tileGridSize=(8, 8))
    a = clahe.apply(gray)

    # Candidate B: Adaptive threshold (handles uneven lighting)
    b = cv2.medianBlur(gray, 3)
    b = cv2.adaptiveThreshold(
        b, 255,
        cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
        cv2.THRESH_BINARY, 31, 10
    )

    # Candidate C: Contrast stretch + light blur (no binarization)
    c = cv2.normalize(gray, None, 0, 255, cv2.NORM_MINMAX)
    c = cv2.GaussianBlur(c, (3, 3), 0)

    def score(img_):
        # Higher = more “texty”: use edge energy as a cheap proxy
        sobelx = cv2.Sobel(img_, cv2.CV_32F, 1, 0, ksize=3)
        sobely = cv2.Sobel(img_, cv2.CV_32F, 0, 1, ksize=3)
        return float(np.mean(np.abs(sobelx)) + np.mean(np.abs(sobely)))

    # Binarize A & C too for fair comparison
    _, a_bin = cv2.threshold(a, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    _, c_bin = cv2.threshold(c, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)

    cand = max([(a_bin, score(a_bin)), (b, score(b)), (c_bin, score(c_bin))], key=lambda t: t[1])[0]

    # Slight morphology to reduce speckle and thicken faint strokes
    cand = cv2.medianBlur(cand, 3)
    cand = cv2.dilate(cand, np.ones((1, 2), np.uint8), iterations=1)

    out = img_path + "_clean.png"
    cv2.imwrite(out, cand)
    return out


def main():
    result = {"merchant":"","amount":"","date":"","items":[],"raw_text":""}
    try:
        print("DEBUG: start", file=sys.stderr, flush=True)

        if len(sys.argv) < 2:
            print(json.dumps(result, ensure_ascii=False))
            return

        img_path = sys.argv[1]
        if not (os.path.isfile(img_path) and os.path.getsize(img_path) > 0):
            result["error"] = "file_missing_or_empty"
            print(json.dumps(result, ensure_ascii=False))
            return

        try:
            ocr = PaddleOCR(
                lang="en",
                use_angle_cls=True,
                det_db_thresh=0.30,
                det_db_box_thresh=0.50,
                det_limit_side_len=1536,
                drop_score=0.30,
                use_gpu=False,
            )
        except TypeError:
            # Older PaddleOCR versions may not accept some args; fall back gracefully
            ocr = PaddleOCR(lang="en", use_angle_cls=True)

        print("DEBUG: preprocessing", file=sys.stderr, flush=True)
        clean_path = preprocess_image(img_path)
        print(f"DEBUG: clean_path={clean_path}", file=sys.stderr, flush=True)

        print("DEBUG: calling OCR", file=sys.stderr, flush=True)
        res = None
        try:
            print("DEBUG: ocr(clean_path)...", file=sys.stderr, flush=True)
            res = ocr.ocr(clean_path)
            print("DEBUG: ocr(clean_path) ok", file=sys.stderr, flush=True)
        except Exception as e1:
            print(f"DEBUG: ocr(clean_path) failed: {e1}", file=sys.stderr, flush=True)
            try:
                print("DEBUG: ocr(original)...", file=sys.stderr, flush=True)
                res = ocr.ocr(img_path)
                print("DEBUG: ocr(original) ok", file=sys.stderr, flush=True)
            except Exception as e2:
                print(f"DEBUG: ocr(original) failed: {e2}", file=sys.stderr, flush=True)
                result["error"] = str(e2)
                print(json.dumps(result, ensure_ascii=False))
                return

        print(f"DEBUG: OCR done; type={type(res)}", file=sys.stderr, flush=True)

        # Normalize result shape before flattening
        if isinstance(res, list) and len(res) == 1 and isinstance(res[0], (list, tuple)):
            res = res[0]
        lines = _flatten_text(res)
        # If preprocessing hurt recognition, fall back to original image
        if len(lines) < 3:
            try:
                res2 = ocr.ocr(img_path)  # original, no preprocessing
                lines2 = _flatten_text(res2)
                if len(lines2) > len(lines):
                    lines = lines2
            except Exception:
                pass

        lines = [ln for ln in lines if not re.search(r"^[A-Za-z]:\\\\|^/+", ln)]
        _debug_numbers(lines)

        data = parse_fields(lines)
        result.update(data)

    except Exception as e:
        result["error"] = f"{type(e).__name__}: {e}"

    # ALWAYS print and log
    out = json.dumps(result, ensure_ascii=False)
    print(out)
    try:
        with open(os.path.join(os.path.dirname(__file__), "ocr_last_output.txt"), "w", encoding="utf-8") as f:
            f.write(out + "\n")
    except:
        pass

if __name__ == "__main__":
    main()
