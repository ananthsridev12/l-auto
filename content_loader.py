import csv
import json
import calendar
from datetime import date, datetime
from pathlib import Path

DATE_FORMATS = ["%m/%d/%Y", "%d-%b-%Y", "%d-%b-%y", "%Y-%m-%d", "%m/%d/%y"]


def _detect_encoding(path):
    for enc in ("utf-8-sig", "utf-8", "cp1252"):
        try:
            Path(path).read_text(encoding=enc)
            return enc
        except (UnicodeDecodeError, LookupError):
            continue
    return "cp1252"


def load_all_rows(csv_path):
    enc = _detect_encoding(csv_path)
    with open(csv_path, encoding=enc, newline="") as f:
        return list(csv.DictReader(f))


def parse_date(raw):
    raw = raw.strip()
    for fmt in DATE_FORMATS:
        try:
            return datetime.strptime(raw, fmt).date()
        except ValueError:
            continue
    return None


def get_campaign_id(row):
    return (row.get("Campaign_ID") or row.get("Campign_ID") or "").strip()


def find_row_for_date(rows, target_date):
    for row in rows:
        raw = (row.get("Date") or "").strip()
        if not raw:
            continue
        if parse_date(raw) == target_date:
            return row
    return None


def find_slides(campaign_id, output_dir):
    folder = Path(output_dir) / campaign_id
    if not folder.exists():
        return []
    return sorted(folder.glob("slide_*.png"))


def find_json_file(campaign_id, content_dir):
    content_dir = Path(content_dir)
    for sub in ["carousels", "Batch 01"]:
        p = content_dir / sub / f"{campaign_id}.json"
        if p.exists():
            return p
    return None


def load_caption(row, campaign_id, content_dir):
    cap   = (row.get("Post Caption") or "").strip()
    title = (row.get("Topic / Title") or "").strip()

    if not cap:
        jp = find_json_file(campaign_id, content_dir)
        if jp:
            data = json.loads(jp.read_text(encoding="utf-8"))
            cap  = data.get("caption", "")
            tags = " ".join(data.get("hashtags", []))
            if tags and tags not in cap:
                cap = cap.rstrip() + "\n\n" + tags

    if title and cap:
        cap = f"{title}\n\n{cap}"
    elif title:
        cap = title

    return cap


def get_all_scheduled_dates(rows):
    result = []
    for row in rows:
        raw = (row.get("Date") or "").strip()
        if not raw:
            continue
        d = parse_date(raw)
        if not d:
            continue
        cid = get_campaign_id(row)
        fmt = (row.get("Final_Format") or "").strip()
        result.append({
            "date":        d.isoformat(),
            "campaign_id": cid,
            "format":      fmt,
            "title":       (row.get("Topic / Title") or "").strip(),
            "has_caption": bool((row.get("Post Caption") or "").strip()),
        })
    return result


def build_calendar_grid(year, month, scheduled_by_date):
    weeks = calendar.monthcalendar(year, month)
    grid  = []
    for week in weeks:
        row = []
        for day in week:
            if day == 0:
                row.append(None)
            else:
                d    = date(year, month, day).isoformat()
                info = scheduled_by_date.get(d)
                row.append({"day": day, "date": d, "post": info})
        grid.append(row)
    return grid
