#!/usr/bin/env python3
"""Lit directement la pointeuse ZKTeco (port 4370) : noms + historique complet,
puis ecrit 2 fichiers CSV (ouvrables dans Excel) : pointages bruts et rapport retards/absences.

Installation (une seule fois, sur un PC connecte au meme reseau que la pointeuse) :
    pip install pyzk
Utilisation :
    python zk_export.py --ip 192.168.1.201 --from 2026-10-01
Options : --ids 450,451,...  (par defaut les 15 employes)  --offset-hours -7  (corriger l'heure de la pointeuse)
"""
import argparse, csv, datetime as dt
from zk import ZK

DEFAULT_IDS = "450,451,452,453,454,455,456,457,1,458,459,460,461,462,463"
START_AM, START_PM, TOL = "08:00", "14:00", 15

def mins(hhmm): h, m = hhmm.split(":"); return int(h) * 60 + int(m)

def analyse(times):
    times = sorted(times); clean = []
    for t in times:                                   # doublons < 2 min ignores
        if not clean or mins(t) - mins(clean[-1]) >= 2: clean.append(t)
    am = [t for t in clean if t < "13:00"]; pm = [t for t in clean if t >= "13:00"]
    r = dict(in1=None, out1=None, in2=None, out2=None)
    if am:
        if len(am) == 1 and am[0] >= "11:00": r["out1"] = am[0]
        else: r["in1"] = am[0]; r["out1"] = am[-1] if len(am) > 1 else None
    if pm:
        if len(pm) == 1 and pm[0] >= "17:00": r["out2"] = pm[0]
        else: r["in2"] = pm[0]; r["out2"] = pm[-1] if len(pm) > 1 else None
    return r

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--ip", required=True); ap.add_argument("--port", type=int, default=4370)
    ap.add_argument("--password", type=int, default=0)
    ap.add_argument("--from", dest="d_from", default=dt.date.today().replace(day=1).isoformat())
    ap.add_argument("--to", dest="d_to", default=dt.date.today().isoformat())
    ap.add_argument("--ids", default=DEFAULT_IDS)
    ap.add_argument("--offset-hours", type=float, default=0.0, help="ajouter ces heures a l'heure de la pointeuse")
    ap.add_argument("--workdays", default="1,2,3,4,5,6", help="1=lundi ... 7=dimanche")
    a = ap.parse_args()
    ids = {x.strip() for x in a.ids.split(",") if x.strip()}
    d_from, d_to = dt.date.fromisoformat(a.d_from), dt.date.fromisoformat(a.d_to)
    workdays = {int(x) for x in a.workdays.split(",")}

    conn = ZK(a.ip, port=a.port, timeout=15, password=a.password, force_udp=False, ommit_ping=True).connect()
    try:
        conn.disable_device()
        names = {str(u.user_id): (u.name or "").strip() for u in conn.get_users()}
        att = conn.get_attendance()
    finally:
        try: conn.enable_device()
        except Exception: pass
        conn.disconnect()

    off = dt.timedelta(hours=a.offset_hours); days = {}; raw = []
    for r in att:
        uid = str(r.user_id)
        if uid not in ids: continue
        t = r.timestamp + off
        if not (d_from <= t.date() <= d_to): continue
        raw.append((t, uid)); days.setdefault(t.date(), {}).setdefault(uid, []).append(t.strftime("%H:%M"))
    raw.sort()

    with open("pointages_bruts.csv", "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f, delimiter=";"); w.writerow(["Date", "Heure", "ID", "Nom"])
        for t, uid in raw: w.writerow([t.date(), t.strftime("%H:%M:%S"), uid, names.get(uid, "")])

    first = min(days) if days else d_from
    lim1, lim2 = mins(START_AM) + TOL, mins(START_PM) + TOL
    today = dt.date.today()
    with open("rapport_employes.csv", "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f, delimiter=";")
        w.writerow(["Date", "ID", "Nom", "Entrée matin", "Sortie midi", "Entrée ap-midi", "Sortie soir", "Durée", "Statut"])
        d = d_to
        while d >= max(d_from, first):
            for uid in sorted(ids, key=int):
                t = days.get(d, {}).get(uid, [])
                if not t and (d.isoweekday() not in workdays or d >= today): continue
                r = analyse(t) if t else dict(in1=None, out1=None, in2=None, out2=None); tags = []
                if not t: tags.append("Absent (journée)")
                else:
                    if r["in1"] and mins(r["in1"]) > lim1: tags.append("Retard de %d min (matin)" % (mins(r["in1"]) - mins(START_AM)))
                    if r["in2"] and mins(r["in2"]) > lim2: tags.append("Retard de %d min (après-midi)" % (mins(r["in2"]) - mins(START_PM)))
                    if d < today:
                        if not r["in1"] and not r["out1"]: tags.append("Absent matin")
                        if not r["in2"] and not r["out2"]: tags.append("Absent après-midi")
                    if not tags: tags.append("À l'heure")
                m = 0
                if r["in1"] and r["out1"]: m += mins(r["out1"]) - mins(r["in1"])
                if r["in2"] and r["out2"]: m += mins(r["out2"]) - mins(r["in2"])
                w.writerow([d, uid, names.get(uid, ""), r["in1"] or "", r["out1"] or "", r["in2"] or "", r["out2"] or "",
                            "%dh%02d" % divmod(m, 60) if m else "", " / ".join(tags)])
            d -= dt.timedelta(days=1)
    print("OK : %d pointages, %d utilisateurs lus. Fichiers : pointages_bruts.csv, rapport_employes.csv" % (len(raw), len(names)))
    print("Utilisateurs trouvés :", ", ".join("%s=%s" % (k, names[k]) for k in sorted(names, key=lambda x: int(x) if x.isdigit() else 0) if k in ids))

if __name__ == "__main__":
    main()
