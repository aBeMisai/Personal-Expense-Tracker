import re

class ReceiptCategorizer:
    def __init__(self):
        self.rules = [
            # Food & Dining / Groceries
            (re.compile(r"\b(oishi|spritzer|milo|nestl[eé]|maggi|noodle|noodles|mee|biscuit|chips?|pan(chos)?|bread|milk|yogurt|coffee|kopi|tea|teh|sugar|rice|nasi|ayam|chicken|grill|pasta|butter|buttermilk|burger|sandwich|egg|telur|snack|drink|beverage|mineral\s*water|air\s*mineral|orange|juice|family\s*mart|speed\s*mart|bungkus|coriander)\b", re.I), "Food & Dining"),
            # Restaurant add-ons (service charge, rounding) -> treat as dining
            (re.compile(r"\b(service\s*charge|rounding|s(?:vc)?\.?\s*chg)\b", re.I), "Food & Dining"),
            # Personal Care
            (re.compile(r"\b(antabax|dettol|garnier|nivea|lifebuoy|shampoo|conditioner|toothpaste|toothbrush|soap|body\s?w(ash)?|face\s?w(ash)?|lotion|deodorant|skincare|shower\s*cream|turbolight|foam|cleanser)\b", re.I), "Personal Care"),
            # Household
            (re.compile(r"\b(detergent|softener|tissue|toilet\s?paper|napkin|sponge|bleach|cleaner|battery|trash\s?bag|aluminum\s?foil|plastic\s?wrap)\b", re.I), "Household"),
            # Transportation
            (re.compile(r"\b(petrol|fuel|diesel|primax|pump|parking|grab|tng|touch\s*'?n\s*go)\b", re.I), "Transportation"),
            # Bills & Utilities (no bare 'tm' to avoid 'TMN')
            (re.compile(r"\b(maxis|hotlink|unifi|tenaga|electric(ity)?|water|internet|mobile|postpaid|prepaid)\b", re.I), "Bills & Utilities"),
            # Health
            (re.compile(r"\b(pharmacy|clinic|hospital|vitamin|supplement)\b", re.I), "Health & Fitness"),
            # Shopping / general retail
            (re.compile(r"\b(nike|uniqlo|shopee|lazada|mr\s*diy|sephora|watsons|guardian)\b", re.I), "Shopping"),
            # Entertainment
            (re.compile(r"\b(cinema|movie|spotify|netflix|voucher|top\s*up)\b", re.I), "Entertainment & Leisure"),
        ]

    def predict(self, text: str) -> str:
        t = text.lower()
        for rx, cat in self.rules:
            if rx.search(t):
                return cat
        return "Other"

    def add_rule(self, pattern: str, category: str):
        self.rules.append((re.compile(pattern, re.I), category))

    def get_all_categories(self):
        return sorted({cat for _, cat in self.rules})
