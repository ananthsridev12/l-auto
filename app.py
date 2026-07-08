import json
import os
import secrets
import time
import threading
import urllib.parse
import webbrowser
from datetime import date, datetime
from pathlib import Path

from flask import (Flask, jsonify, redirect, render_template,
                   request, send_from_directory, session, url_for)
import requests as http

from content_loader import (build_calendar_grid, find_slides, get_all_scheduled_dates,
                             get_campaign_id, load_all_rows, load_caption,
                             find_row_for_date)

app = Flask(__name__)
app.secret_key = os.environ.get("FLASK_SECRET", secrets.token_hex(32))

BASE_DIR      = Path(__file__).parent
TOKEN_PATH    = BASE_DIR / "token.json"
SETTINGS_PATH = BASE_DIR / "settings.json"

LI_VERSION   = "202506"
AUTH_URL     = "https://www.linkedin.com/oauth/v2/authorization"
TOKEN_URL    = "https://www.linkedin.com/oauth/v2/accessToken"
REDIRECT_URI = "http://localhost:5000/auth/callback"
SCOPES       = "openid profile w_member_social"
API_BASE     = "https://api.linkedin.com"


# ── settings & token ─────────────────────────────────────────────────────────

def load_settings():
    if SETTINGS_PATH.exists():
        return json.loads(SETTINGS_PATH.read_text(encoding="utf-8"))
    return {}

def save_settings(data):
    SETTINGS_PATH.write_text(json.dumps(data, indent=2), encoding="utf-8")

def get_content_dir():
    return Path(load_settings().get("content_dir", str(BASE_DIR / "content")))

def load_token():
    if TOKEN_PATH.exists():
        return json.loads(TOKEN_PATH.read_text(encoding="utf-8"))
    return None

def save_token(data):
    TOKEN_PATH.write_text(json.dumps(data, indent=2), encoding="utf-8")

def is_setup_complete():
    s = load_settings()
    return bool(s.get("client_id") and s.get("client_secret"))

def is_authenticated():
    return load_token() is not None


# ── LinkedIn API ──────────────────────────────────────────────────────────────

def li_headers(access_token):
    return {
        "Authorization":             f"Bearer {access_token}",
        "LinkedIn-Version":          LI_VERSION,
        "X-Restli-Protocol-Version": "2.0.0",
        "Content-Type":              "application/json",
    }

def upload_image(access_token, person_urn, image_path):
    resp = http.post(
        f"{API_BASE}/rest/images?action=initializeUpload",
        headers=li_headers(access_token),
        json={"initializeUploadRequest": {"owner": person_urn}},
    )
    if not resp.ok:
        raise Exception(f"Image init failed {resp.status_code}: {resp.text}")
    data = resp.json()["value"]
    put  = http.put(
        data["uploadUrl"],
        data=open(image_path, "rb"),
        headers={"Authorization": f"Bearer {access_token}", "Content-Type": "application/octet-stream"},
    )
    put.raise_for_status()
    return data["image"]

def upload_document(access_token, person_urn, pdf_path):
    resp = http.post(
        f"{API_BASE}/rest/documents?action=initializeUpload",
        headers=li_headers(access_token),
        json={"initializeUploadRequest": {"owner": person_urn}},
    )
    if not resp.ok:
        raise Exception(f"Document init failed {resp.status_code}: {resp.text}")
    data = resp.json()["value"]
    put  = http.put(
        data["uploadUrl"],
        data=open(pdf_path, "rb"),
        headers={"Authorization": f"Bearer {access_token}", "Content-Type": "application/octet-stream"},
    )
    put.raise_for_status()
    return data["document"]

def pngs_to_pdf(png_paths, pdf_path):
    from PIL import Image
    images = [Image.open(p).convert("RGB") for p in png_paths]
    images[0].save(pdf_path, format="PDF", save_all=True,
                   append_images=images[1:], resolution=150)

def create_linkedin_post(access_token, person_urn, commentary, content=None):
    body = {
        "author":         person_urn,
        "commentary":     commentary,
        "visibility":     "PUBLIC",
        "distribution":   {"feedDistribution": "MAIN_FEED"},
        "lifecycleState": "PUBLISHED",
    }
    if content:
        body["content"] = content
    resp = http.post(f"{API_BASE}/rest/posts", headers=li_headers(access_token), json=body)
    if not resp.ok:
        raise Exception(f"Post failed {resp.status_code}: {resp.text}")
    return resp.headers.get("x-restli-id", "unknown")


# ── routes ───────────────────────────────────────────────────────────────────

@app.route("/")
def index():
    if not is_setup_complete():
        return redirect(url_for("setup"))
    if not is_authenticated():
        return redirect(url_for("auth_page"))
    return redirect(url_for("today"))


@app.route("/setup", methods=["GET", "POST"])
def setup():
    if request.method == "POST":
        s = load_settings()
        s["client_id"]     = request.form.get("client_id", "").strip()
        s["client_secret"] = request.form.get("client_secret", "").strip()
        s["content_dir"]   = request.form.get("content_dir", "").strip() or str(BASE_DIR / "content")
        save_settings(s)
        return redirect(url_for("auth_page"))
    return render_template("setup.html", settings=load_settings(), redirect_uri=REDIRECT_URI)


@app.route("/auth")
def auth_page():
    return render_template("auth.html", token=load_token())


@app.route("/auth/start")
def auth_start():
    s     = load_settings()
    state = secrets.token_urlsafe(16)
    session["oauth_state"] = state
    url = AUTH_URL + "?" + urllib.parse.urlencode({
        "response_type": "code",
        "client_id":     s["client_id"],
        "redirect_uri":  REDIRECT_URI,
        "scope":         SCOPES,
        "state":         state,
    })
    return redirect(url)


@app.route("/auth/callback")
def auth_callback():
    code = request.args.get("code")
    if not code:
        return "Authentication failed — no code received.", 400
    s    = load_settings()
    resp = http.post(TOKEN_URL, data={
        "grant_type":    "authorization_code",
        "code":          code,
        "redirect_uri":  REDIRECT_URI,
        "client_id":     s["client_id"],
        "client_secret": s["client_secret"],
    })
    resp.raise_for_status()
    token = resp.json()
    me    = http.get("https://api.linkedin.com/v2/userinfo",
                     headers={"Authorization": f"Bearer {token['access_token']}"}).json()
    token["person_urn"] = f"urn:li:person:{me['sub']}"
    token["name"]       = me.get("name", "")
    save_token(token)
    return redirect(url_for("today"))


@app.route("/auth/logout")
def auth_logout():
    TOKEN_PATH.unlink(missing_ok=True)
    return redirect(url_for("auth_page"))


@app.route("/today")
def today():
    return redirect(url_for("post_for_date", date_str=date.today().isoformat()))


@app.route("/post/<date_str>")
def post_for_date(date_str):
    token = load_token()
    content_dir = get_content_dir()

    try:
        target = datetime.strptime(date_str, "%Y-%m-%d").date()
    except ValueError:
        return "Invalid date", 400

    csv_files = sorted(content_dir.glob("*.csv")) if content_dir.exists() else []
    post_data = None

    if csv_files:
        rows = load_all_rows(csv_files[0])
        row  = find_row_for_date(rows, target)
        if row:
            cid     = get_campaign_id(row)
            fmt     = row.get("Final_Format", "").strip()
            title   = row.get("Topic / Title", "").strip()
            caption = load_caption(row, cid, content_dir)
            slides  = [f"/slides/{cid}/{s.name}"
                       for s in find_slides(cid, content_dir / "output")]
            post_data = {
                "campaign_id": cid,
                "format":      fmt,
                "title":       title,
                "caption":     caption,
                "slides":      slides,
                "date":        target.strftime("%B %d, %Y"),
            }

    return render_template(
        "today.html",
        post=post_data,
        date_str=date_str,
        active_page="today",
        user_name=token["name"] if token else "",
    )


@app.route("/calendar")
def calendar_view():
    token       = load_token()
    content_dir = get_content_dir()
    csv_files   = sorted(content_dir.glob("*.csv")) if content_dir.exists() else []

    scheduled_by_date = {}
    if csv_files:
        for item in get_all_scheduled_dates(load_all_rows(csv_files[0])):
            scheduled_by_date[item["date"]] = item

    today  = date.today()
    grid   = build_calendar_grid(today.year, today.month, scheduled_by_date)
    import calendar as cal_mod
    month_name = cal_mod.month_name[today.month]

    return render_template(
        "calendar.html",
        grid=grid,
        month_name=month_name,
        year=today.year,
        today=today.isoformat(),
        active_page="calendar",
        user_name=token["name"] if token else "",
    )


@app.route("/slides/<campaign_id>/<filename>")
def serve_slide(campaign_id, filename):
    content_dir = get_content_dir()
    folder = content_dir / "output" / campaign_id
    return send_from_directory(folder, filename)


@app.route("/api/post", methods=["POST"])
def api_post():
    token = load_token()
    if not token:
        return jsonify({"success": False, "error": "Not authenticated"}), 401

    data        = request.get_json()
    campaign_id = data.get("campaign_id", "")
    caption     = data.get("caption", "")
    fmt         = data.get("format", "")
    content_dir = get_content_dir()
    output_dir  = content_dir / "output"

    try:
        access_tok = token["access_token"]
        person_urn = token["person_urn"]
        slides     = find_slides(campaign_id, output_dir)

        if fmt in ("Text Post", "Poll") or not slides:
            post_urn = create_linkedin_post(access_tok, person_urn, caption)
        elif fmt == "Single Image" or len(slides) == 1:
            image_urn = upload_image(access_tok, person_urn, slides[0])
            post_urn  = create_linkedin_post(
                access_tok, person_urn, caption,
                content={"media": {"title": campaign_id, "id": image_urn}},
            )
        else:
            pdf_path = output_dir / campaign_id / f"{campaign_id}.pdf"
            pngs_to_pdf(slides, pdf_path)
            time.sleep(2)
            doc_urn  = upload_document(access_tok, person_urn, pdf_path)
            time.sleep(3)
            post_urn = create_linkedin_post(
                access_tok, person_urn, caption,
                content={"media": {"title": campaign_id, "id": doc_urn}},
            )

        return jsonify({"success": True, "post_urn": post_urn})
    except Exception as e:
        return jsonify({"success": False, "error": str(e)}), 500


if __name__ == "__main__":
    def _open():
        time.sleep(1.5)
        webbrowser.open("http://localhost:5000")
    threading.Thread(target=_open, daemon=True).start()
    app.run(debug=False, port=5000)
