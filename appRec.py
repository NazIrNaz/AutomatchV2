# ---------------------------------------------
# appRec.py — Automatch Rule-based API (old-logic rework)
# ---------------------------------------------
from flask import Flask, request, jsonify
from flask_caching import Cache
from flask_limiter import Limiter
from flask_limiter.util import get_remote_address
from flask_cors import CORS
from babel.numbers import format_currency

import mysql.connector
import pandas as pd
import locale
import logging
import json
import re
import os

APP_VERSION = "automatch-rules-v2-OLDLOGIC-2025-10-01"

# ---------- Flask base ----------
app = Flask(__name__)
CORS(app)

# Malaysian formatting (for Babel we'll still pass locale, but set OS locale too if available)
try:
    locale.setlocale(locale.LC_ALL, 'en_MY.UTF-8')
except Exception:
    pass

# Cache + rate limit
cache = Cache(app, config={"CACHE_TYPE": "simple"})
limiter = Limiter(key_func=get_remote_address)
limiter.init_app(app)

logging.basicConfig(level=logging.INFO)

# ---------- DB ----------
DB_CONFIG = {
    'host': 'localhost',
    'user': 'root',
    'password': '@dmin321',
    'database': 'fypbetatest'
}

# ---------- Weights ----------
WEIGHTS = {
    "financial_score": 0.40,
    "practical_needs_score": 0.30,
    "brand_preference_score": 0.20,
    "feature_score": 0.10,
}

# ---------- Helpers ----------
def fmt_myr(v):
    try:
        return format_currency(float(v or 0), 'MYR', locale='en_MY')
    except Exception:
        return f"RM {float(v or 0):,.2f}"

def calculate_monthly_payment(principal, annual_rate, years, down_pct=10.0):
    """
    principal: sticker price
    annual_rate: fraction (e.g. 0.035)
    down_pct: percent (e.g. 10 for 10%)
    """
    try:
        p = float(principal or 0.0)
        dp = float(down_pct or 0.0) / 100.0
        loan = p * (1.0 - dp)
        n = int(years or 0) * 12
        if n <= 0 or loan <= 0:
            return 0.0
        r = float(annual_rate or 0.0) / 12.0
        if r <= 0:
            return loan / n
        return loan * (r * (1 + r) ** n) / ((1 + r) ** n - 1)
    except Exception:
        return 0.0

def price_from_monthly(mpay, years, rate_annual, down_pct):
    """Invert amortization to estimate sticker price supported by a monthly cap."""
    r = float(rate_annual or 0.0) / 12.0
    n = int(years or 0) * 12
    if n <= 0:
        return 0.0
    if r <= 0:
        loan = float(mpay or 0.0) * n
    else:
        loan = float(mpay or 0.0) * ((1 + r) ** n - 1) / (r * (1 + r) ** n)
    return loan / max(1e-9, 1.0 - float(down_pct or 0.0) / 100.0)

def assign_price_category(price):
    """Purely for label in UI; not a hard filter."""
    p = float(price or 0)
    if p < 70_000:
        return "Entry"
    if p < 120_000:
        return "Mid"
    if p < 200_000:
        return "Upper-Mid"
    return "Premium"

def _normalize_brands(val):
    """Accept list or comma/JSON-like string; emit lowercase list."""
    if isinstance(val, list):
        return [str(x).strip().lower() for x in val if str(x).strip()]
    if isinstance(val, str):
        s = val.strip()
        if s.startswith("[") and s.endswith("]"):
            try:
                arr = json.loads(s.replace("'", '"'))
                if isinstance(arr, list):
                    return [str(x).strip().lower() for x in arr if str(x).strip()]
            except Exception:
                pass
        return [p.strip().lower() for p in re.split(r"[,\n;]+", s) if p.strip()]
    return []

def _wanted_configs(val):
    """Allow 'Sedan,SUV' etc."""
    return [c.strip().lower() for c in str(val or "").split(",") if c.strip()]

def calculate_score(row, user):
    """Old-system scoring with robust numerics & fallbacks."""
    mi = float(user['monthly_income']); me = float(user['monthly_expenses'])
    fam = max(1, int(user['family_size']))
    cap = max(1.0, (mi - me) * 0.20)   # 20% of disposable by default
    min_aff = cap * 0.5

    mpay = float(row.get('monthly_payment') or 0.0)
    if mpay > cap:
        financial = 0.0
    elif mpay >= min_aff:
        financial = 1.0
    else:
        financial = mpay / min_aff

    seats = float(row.get('seating_capacity') or 0.0)
    practical = 1.0 if seats >= fam else (seats / fam if fam > 0 else 0.0)

    brand_hit = 1.0 if row.get('brand_norm') in user['preferred_brands'] else 0.5

    hp = float(row.get('horsepower') or 0.0)
    ts = float(row.get('top_speed') or 0.0)
    feature = 1.0 if (hp > 0 and ts > 0) else 0.8

    total = (WEIGHTS["financial_score"] * financial +
             WEIGHTS["practical_needs_score"] * practical +
             WEIGHTS["brand_preference_score"] * brand_hit +
             WEIGHTS["feature_score"] * feature)

    return {
        "financial_score": round(financial, 3),
        "practical_needs_score": round(practical, 3),
        "brand_preference_score": round(brand_hit, 3),
        "feature_score": round(feature, 3),
        "total_score": round(float(total), 3)
    }

# ---------- Data access ----------
@cache.cached(timeout=300, key_prefix='vehicle_data_v2')
def fetch_vehicle_data():
    """
    Pull vehicles, price, configuration, and key specs.
    Seats come from vehicle_specifications (category/dimensions or key='Seats').
    """
    conn = mysql.connector.connect(**DB_CONFIG)
    try:
        sql = r"""
        SELECT
            v.vehicle_id,
            v.brand,
            v.name,
            v.model,
            v.year,
            v.image_url,
            v.vehicle_url,
            COALESCE(cfg.vehicle_configuration, '') AS vehicle_configuration,
            COALESCE(pr.price, 0) AS price,
            COALESCE(seatq.seating_capacity, NULL) AS seating_capacity,
            COALESCE(hpq.hp, NULL) AS horsepower,
            COALESCE(tsq.ts, NULL) AS top_speed
        FROM vehicle_data v
        LEFT JOIN vehicle_price pr ON pr.vehicle_id = v.vehicle_id
        LEFT JOIN vehicle_configurations cfg ON cfg.vehicle_id = v.vehicle_id

        /* Seats */
        LEFT JOIN (
            SELECT
                vs.vehicle_id,
                /* prefer dimensions/seats, else any 'seats' key */
                COALESCE(
                  MAX(CASE WHEN LOWER(vs.category)='dimensions'
                            AND LOWER(vs.spec_key) IN ('seats','seat','seating capacity')
                           THEN vs.spec_value END),
                  MAX(CASE WHEN LOWER(vs.spec_key) IN ('seats','seat','seating capacity')
                           THEN vs.spec_value END),
                  MAX(CASE WHEN vs.spec_key = 'Seats' THEN vs.spec_value END)
                ) AS seating_capacity
            FROM vehicle_specifications vs
            GROUP BY vs.vehicle_id
        ) AS seatq ON seatq.vehicle_id = v.vehicle_id

        /* Horsepower */
        LEFT JOIN (
            SELECT
                vs.vehicle_id,
                COALESCE(
                  MAX(CASE WHEN LOWER(vs.category) IN ('engine','performance')
                            AND LOWER(vs.spec_key) IN ('horsepower','hp','power')
                           THEN vs.spec_value END),
                  MAX(CASE WHEN LOWER(vs.spec_key) IN ('horsepower','hp','power')
                           THEN vs.spec_value END),
                  MAX(CASE WHEN vs.spec_key='Horsepower' THEN vs.spec_value END)
                ) AS hp
            FROM vehicle_specifications vs
            GROUP BY vs.vehicle_id
        ) AS hpq ON hpq.vehicle_id = v.vehicle_id

        /* Top speed */
        LEFT JOIN (
            SELECT
                vs.vehicle_id,
                COALESCE(
                  MAX(CASE WHEN LOWER(vs.category) IN ('performance','engine')
                            AND LOWER(vs.spec_key) IN ('top speed','topspeed','max speed','maximum speed')
                           THEN vs.spec_value END),
                  MAX(CASE WHEN LOWER(vs.spec_key) IN ('top speed','topspeed','max speed','maximum speed')
                           THEN vs.spec_value END),
                  MAX(CASE WHEN vs.spec_key='Top Speed' THEN vs.spec_value END)
                ) AS ts
            FROM vehicle_specifications vs
            GROUP BY vs.vehicle_id
        ) AS tsq ON tsq.vehicle_id = v.vehicle_id
        """
        cur = conn.cursor(dictionary=True)
        cur.execute(sql)
        rows = cur.fetchall()
        df = pd.DataFrame(rows)
        return df
    finally:
        conn.close()

# ---------- Routes ----------
@app.route("/health", methods=["GET"])
def health():
    return jsonify({
        "status": "ok",
        "version": APP_VERSION,
        "file": os.path.abspath(__file__)
    }), 200

@limiter.limit("10 per minute")
@app.route("/recommend", methods=["POST"])
def recommend():
    try:
        user = request.get_json(force=True) or {}
        required = [
            'monthly_income','preferred_brands','family_size','monthly_expenses',
            'vehicle_configuration','down_payment','loan_tenure','interest_rate'
        ]
        missing = [k for k in required if k not in user]
        if missing:
            return jsonify({"error": f"Missing required keys: {missing}"}), 400

        # Normalize inputs
        mi   = float(user['monthly_income'])
        me   = float(user['monthly_expenses'])
        fam  = int(user['family_size'])
        brands = _normalize_brands(user.get('preferred_brands'))
        configs = _wanted_configs(user.get('vehicle_configuration'))
        down = float(user['down_payment'])
        years = int(user['loan_tenure'])
        rate = float(user['interest_rate']) / 100.0

        # Cap logic (old system): friendlier default + tolerance if user didn't type a cap
        explicit_cap = user.get('max_monthly_payment') not in (None, "", 0, "0")
        if explicit_cap:
            max_monthly = float(user.get('max_monthly_payment') or 0)
        else:
            max_monthly = max(0.0, (mi - me) * 0.20)  # 20% of disposable by default

        # Load data
        df = fetch_vehicle_data()
        if df is None or df.empty:
            return jsonify({"error": "No vehicle data available."}), 404

        # Coerce numerics safely
        for col in ['price','horsepower','top_speed','seating_capacity']:
            if col in df.columns:
                df[col] = pd.to_numeric(df[col], errors='coerce')

        df = df.dropna(subset=['price'])
        df = df[df['price'] > 0]

        # Normalize match fields
        df['brand_norm']  = df.get('brand', "").astype(str).str.strip().str.lower()
        df['config_norm'] = df.get('vehicle_configuration', "").astype(str).str.strip().str.lower()

        # Monthly payments
        df['monthly_payment'] = df['price'].apply(
            lambda p: calculate_monthly_payment(p, rate, years, down)
        )

        # Affordability pre-filter with tolerance if cap is auto
        tolerance = 1.25 if not explicit_cap else 1.00
        df = df[df['monthly_payment'] <= max_monthly * tolerance]
        if df.empty:
            return jsonify({"error": "No cars fit the monthly payment limit. Try raising max monthly or adjusting down payment/tenure."}), 404

        # Price band: respect the cap, never exclude cheaper cars
        df['price_category'] = df['price'].apply(assign_price_category)

        # Income band (soft)
        inc_min, inc_max = (mi * 8, mi * 30)

        # Cap-derived ceiling
        cap_price = price_from_monthly(max_monthly, years, rate, down)
        cap_pad = 1.40 if not explicit_cap else 1.15
        band_max = cap_price * cap_pad if cap_price > 0 else inc_max
        final_max = min(inc_max, band_max) if band_max > 0 else inc_max

        # Let very cheap cars through; only ceiling constrain
        df = df[(df['price'] >= 0) & (df['price'] <= final_max)]
        if df.empty:
            return jsonify({"error": "No cars in your feasible price range. Try adjusting down payment/tenure or increase max monthly."}), 404

        # Score (old-system formula)
        df['score_details'] = df.apply(lambda r: calculate_score(r, {
            'monthly_income': mi, 'monthly_expenses': me, 'family_size': fam,
            'preferred_brands': brands
        }), axis=1)
        df['total_score'] = df['score_details'].apply(lambda s: s['total_score'])

        # Preferred set (brand + any config), with graceful relaxation
        preferred = df[
            (df['brand_norm'].isin(brands)) &
            (df['config_norm'].isin(configs) if configs else True)
        ]

        if preferred.empty and brands:
            preferred = df[df['brand_norm'].isin(brands)]
        if preferred.empty and configs:
            preferred = df[df['config_norm'].isin(configs)]
        if preferred.empty:
            preferred = df.copy()

        preferred = preferred.sort_values(by=['price_category','total_score'], ascending=[True, False])

        # Top suggestion + reasoning
        top = preferred.iloc[0]
        tsd = top['score_details']
        reasoning = (
            "This car was recommended because:\n"
            f"- Monthly payment fits: {fmt_myr(top['monthly_payment'])}.\n"
            f"- Seats {int(top.get('seating_capacity') or 0)} vs family size {fam}.\n"
            f"- Brand match: {'Yes' if top['brand_norm'] in brands else 'Partial'}.\n"
            f"- Features: {'complete' if tsd['feature_score'] == 1.0 else 'basic'}."
        )

        # Why-not-others
        others = []
        for _, car in preferred.iloc[1:7].iterrows():
            sd = car['score_details']; gap = round(tsd['total_score'] - sd['total_score'], 2)
            bits = []
            if sd['financial_score'] < tsd['financial_score']: bits.append("it’s less affordable")
            if sd['practical_needs_score'] < tsd['practical_needs_score']: bits.append("it fits family needs worse")
            if sd['brand_preference_score'] < tsd['brand_preference_score']: bits.append("brand isn’t preferred")
            if sd['feature_score'] < tsd['feature_score']: bits.append("features are weaker")
            if not bits: bits.append("overall score is slightly lower")
            others.append({
                "name": car['name'],
                "reasoning": f"{car['name']} was not top because " + ", ".join(bits) + f" (score gap {gap}).",
                "score_details": sd
            })

        # Alternatives (top non-preferred by score)
        alt = df[~df['vehicle_id'].isin(preferred['vehicle_id'])] \
                .sort_values('total_score', ascending=False).head(10)

        def as_view(row):
            return {
                "name": row.get('name'),
                "image_url": row.get('image_url'),
                "price": fmt_myr(row.get('price')),
                "monthly_payment": fmt_myr(row.get('monthly_payment')),
                "details_url": row.get('vehicle_url'),
                "vehicle_id": row.get('vehicle_id'),
                "vehicle_configuration": row.get('vehicle_configuration'),
                "score_breakdown": row.get('score_details'),
            }

        resp = {
            "message": f"We found {len(preferred)} cars that match your preferences!",
            "top_suggestion": {
                "name": top.get('name'),
                "image_url": top.get('image_url'),
                "price": fmt_myr(top.get('price')),
                "monthly_payment": fmt_myr(top.get('monthly_payment')),
                "details_url": top.get('vehicle_url'),
                "vehicle_configuration": top.get('vehicle_configuration'),
                "score_details": tsd,
                "reasoning": reasoning,
                "why_not_other_cars": others
            },
            "preferred": [as_view(r) for _, r in preferred.iterrows()],
            "alternatives": [as_view(r) for _, r in alt.iterrows()],
        }
        return jsonify(resp), 200

    except Exception as e:
        logging.exception("Error in /recommend")
        return jsonify({"error": f"{type(e).__name__}: {e}"}), 500

# ---------- Entrypoint ----------
if __name__ == "__main__":
    # Auto-start friendly for PHP: no reloader; bind localhost:5000
    app.run(host="127.0.0.1", port=5000, debug=False, use_reloader=False, threaded=True)
