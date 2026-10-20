#!/usr/bin/env bash
# Legt in einer Test-Nextcloud den Kalender „MSP Cloud Kalender“ mit erfundenen Testterminen neu an.
# Nur für Testinstanzen: vorhandene Testtermine gleichen Namens werden überschrieben.
# Aufruf: NC_URL=http://localhost:8080 NC_USER=admin NC_PASS=… tools/testdaten.sh
set -euo pipefail

NC_URL=${NC_URL:-http://localhost:8080}
NC_USER=${NC_USER:-admin}
NC_PASS=${NC_PASS:?NC_PASS fehlt}
KALENDER="$NC_URL/remote.php/dav/calendars/$NC_USER/msp-cloud"
auth=(-u "$NC_USER:$NC_PASS" -sS -o /dev/null)

# Kalender anlegen; 405 heißt: gibt es schon.
code=$(curl "${auth[@]}" -w '%{http_code}' -X MKCALENDAR "$KALENDER/" -H 'Content-Type: application/xml' --data \
	'<?xml version="1.0"?><c:mkcalendar xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:set><d:prop><d:displayname>MSP Cloud Kalender</d:displayname><c:supported-calendar-component-set><c:comp name="VEVENT"/></c:supported-calendar-component-set></d:prop></d:set></c:mkcalendar>')
[[ $code == 201 || $code == 405 ]] || { echo "Kalender anlegen: HTTP $code" >&2; exit 1; }

# termin UID BEGINN ENDE TITEL [ZUSATZZEILEN]
termin() {
	local jahr; jahr=$(date +%Y)
	printf 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//MSP-Planung Test//DE\r\nBEGIN:VEVENT\r\nUID:%s\r\nDTSTAMP:%s0101T000000Z\r\nDTSTART;TZID=Europe/Berlin:%s\r\nDTEND;TZID=Europe/Berlin:%s\r\nSUMMARY:%s\r\n%bEND:VEVENT\r\nEND:VCALENDAR\r\n' \
		"$1" "$jahr" "$2" "$3" "$4" "${5:-}" \
		| curl "${auth[@]}" -X PUT "$KALENDER/$1.ics" -H 'Content-Type: text/calendar' --data-binary @- -w '%{http_code}' \
		| grep -qE '^20[14]$' || { echo "Termin $1 anlegen fehlgeschlagen" >&2; exit 1; }
}

# Termine relativ zu heute, damit sie im Standard-Zeitraum der Liste liegen.
tag() { date -d "$1" +%Y%m%d; }
termin workshop-1 "$(tag '+16 days')T140000" "$(tag '+16 days')T170000" 'Lötworkshop Grundschule' 'DESCRIPTION:Erste Zeile der Beschreibung\r\n'
termin serie-1 "$(tag '+1 day')T180000" "$(tag '+1 day')T200000" 'Offene Werkstatt' 'RRULE:FREQ=WEEKLY;COUNT=20\r\n'
termin privat-1 "$(tag '+3 days')T090000" "$(tag '+3 days')T100000" 'Privater Termin' 'CLASS:PRIVATE\r\n'
for i in $(seq 1 30); do
	termin "fuellung-$i" "$(tag "+$((i + 30)) days")T100000" "$(tag "+$((i + 30)) days")T120000" "Füllertermin $i"
done
echo "Testdaten in $KALENDER angelegt."

# Zweites Konto „eva“ mit Lesefreigabe, zum Prüfen von Rechten und privaten Terminen.
if [[ -n ${NC_EVA_PASS:-} ]]; then
	curl "${auth[@]}" -X POST "$KALENDER/" -H 'Content-Type: application/xml' --data \
		'<?xml version="1.0"?><o:share xmlns:d="DAV:" xmlns:o="http://owncloud.org/ns"><o:set><d:href>principal:principals/users/eva</d:href></o:set></o:share>'
	echo "Kalender für eva nur zum Lesen freigegeben."
fi
