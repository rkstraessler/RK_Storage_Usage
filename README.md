# Storage Usage API

Storage Usage API ist eine kleine Nextcloud-App, die die summierte
Speichernutzung aller bekannten Benutzer als JSON bereitstellt. Zusätzlich
können Administratoren beliebig viele erreichbare Ordner auswählen und deren
Speichernutzung unter frei wählbaren JSON-Schlüsseln separat ausgeben.

## Kompatibilität

- Nextcloud 34
- PHP 8.1 oder neuer

## Endpoint

Nach dem Aktivieren der App ist dieser schreibgeschützte Endpoint verfügbar:

```text
/index.php/apps/storageusage/api/v1/usage
```

Beispiel:

```json
{
    "totalUsage": 2.58,
    "unit": "GiB",
    "totalUsageBytes": 2767769600,
    "baseTotalUsageBytes": 3596755985,
    "excludedUsageBytes": 829986385,
    "cacheTtl": 60,
    "folders": {
        "archive": {
            "usage": 791.54,
            "unit": "MiB",
            "usageBytes": 829986385,
            "excludeFromTotal": true,
            "excludedFromTotal": true,
            "status": "ok"
        }
    }
}
```

Die Ausgabeeinheit für `totalUsage` kann im Nextcloud-Adminbereich eingestellt
werden. `totalUsageBytes` enthält unabhängig davon immer den exakten Wert in
Bytes. `baseTotalUsageBytes` ist die unveränderte Speichernutzung aller
Benutzer. `excludedUsageBytes` zeigt, wie viele Bytes durch konfigurierte
Ordnerausschlüsse einmalig von dieser Basis abgezogen wurden. `folders`
enthält die separat konfigurierten Ordner, jeweils unter dem vom Administrator
festgelegten Schlüssel. Ein nicht mehr erreichbarer Ordner bleibt mit
`status: "unavailable"` sichtbar; seine Verbrauchswerte sind dann `null` und er
wird nicht von der Gesamtsumme abgezogen.

## Einstellungen

Administratoren finden die grafische Konfiguration unter:

```text
Administrationseinstellungen → Storage Usage API
```

Verfügbare Ausgabeeinheiten sind `Auto`, `B`, `kB`, `KiB`, `MB`, `MiB`, `GB`,
`GiB`, `TB` und `TiB`. Die dezimalen SI-Einheiten verwenden den Faktor 1000,
die binären IEC-Einheiten den Faktor 1024. `Auto` wählt abhängig von der Größe
automatisch `B`, `KiB`, `MiB`, `GiB` oder `TiB`.
Die Cache-Zeit kann auf 0, 30, 60, 300, 900 oder 3600 Sekunden gesetzt werden.
Bei 0 wird der Wert für jede Anfrage neu berechnet.
Mit **API-Link öffnen** lässt sich der passende öffentliche Endpoint direkt in
einem neuen Browser-Tab öffnen.

### Separate Ordner

Über **Ordner auswählen** können Administratoren durch die Ordner navigieren,
auf die ihr eigenes Nextcloud-Konto Zugriff hat, und auch Unterordner
auswählen. Für jeden Eintrag lassen sich ein eindeutiger JSON-Schlüssel, eine
eigene Ausgabeeinheit und die Option **Aus Gesamtsumme ausschließen** festlegen.
Die Liste ist nicht auf eine feste Anzahl von Einträgen begrenzt.

Ohne aktivierten Ausschluss wird der Ordner lediglich separat ausgegeben und
bleibt wie bisher Teil von `totalUsage`. Mit aktiviertem Ausschluss wird seine
Größe zusätzlich separat ausgegeben und einmalig von `totalUsage` abgezogen.
Überlappende Ausschlüsse, beispielsweise ein Ordner und einer seiner
Unterordner, werden nicht doppelt abgezogen. `totalUsageBytes` kann dabei nie
kleiner als 0 werden.

Der Status `ok` bedeutet, dass der Ordner erfolgreich ermittelt wurde. Ein
erreichbarer Ordner auf einem Speicher, der nicht in der bisherigen
Benutzer-Gesamtsumme enthalten ist, erhält `not_in_total`: Seine Größe wird
separat ausgegeben, kann aber nicht von einer Summe abgezogen werden, in der sie
gar nicht enthalten ist. `excludedFromTotal` zeigt unabhängig von der
Konfiguration, ob der Eintrag bei dieser Berechnung tatsächlich abgezogen
wurde.

## Datenschutz und Sicherheit

Der Endpoint ist bewusst **öffentlich und ohne Anmeldung erreichbar**. Er gibt
die Gesamtsumme und – sofern konfiguriert – die vom Administrator benannten
JSON-Schlüssel mit den jeweiligen Ordnergrößen zurück. Deshalb dürfen die
Schlüssel selbst keine vertraulichen Informationen enthalten. Interne
Datei-IDs, Speicher-IDs, Benutzerkennungen, Ordnerpfade, Dateinamen und
Dateiinhalte werden nicht ausgegeben.

Wer die Gesamtsumme nicht öffentlich bereitstellen möchte, darf die App nicht
aktivieren oder muss den Endpoint zusätzlich über den vorgeschalteten
Webserver schützen. Wer nur die einzelnen Ordnergrößen nicht veröffentlichen
möchte, darf keine separaten Ordner konfigurieren.

## Cache

- Interner Nextcloud-Local-Cache
- Einstellbare TTL: 0 bis 3600 Sekunden
- Bei konfiguriertem `memcache.local` wird beispielsweise APCu verwendet
- Der erste Request nach Ablauf oder einer geänderten Ordnerkonfiguration
  berechnet die Werte neu
- Weitere Requests innerhalb der eingestellten Cache-Zeit lesen nur den Cache
- Es wird kein rekursiver Festplatten-Scan ausgeführt

## Manuelle Installation

Das Release-Archiv muss genau einen obersten Ordner namens `storageusage`
enthalten. Diesen Ordner in ein konfiguriertes Nextcloud-App-Verzeichnis
entpacken und anschließend aktivieren:

```bash
sudo -u www-data php occ app:enable storageusage
```

Der Benutzername des Webservers kann je nach Installation abweichen.

## App-Store-Veröffentlichung

Die Veröffentlichung benötigt ein auf die App-ID `storageusage` ausgestelltes
Nextcloud-App-Zertifikat. Der private Schlüssel darf niemals in dieses
Repository oder in ein Release-Archiv gelangen. Der vollständige offizielle
Ablauf ist in der
[Nextcloud App Store documentation](https://nextcloudappstore.readthedocs.io/en/latest/developer.html)
beschrieben.

Das vollständige REIKEM-Logo ist als App-Store-Vorschau hinterlegt. Für
Nextclouds App-Symbol wird das eigenständige Vektor-`R` aus `img/app.svg`
verwendet.

## Automatische Releases

Der Workflow `Build, sign and publish release` übernimmt den vollständigen
Release-Prozess. Unter GitHub muss lediglich unter `Actions` der Workflow
ausgewählt, `Run workflow` angeklickt und eine neue Version ohne führendes `v`
eingetragen werden, beispielsweise `2.0.0`.

Der Workflow:

1. prüft die SemVer-Version und erhöht sie in `appinfo/info.xml`,
2. ergänzt das Changelog und erstellt den Versions-Commit sowie das Tag,
3. erzeugt mit Nextcloud 34 die interne `appinfo/signature.json`,
4. installiert die signierte App in einer frischen Nextcloud-34-Instanz und
   ruft den öffentlichen API-Endpunkt auf,
5. erstellt ein reproduzierbares `tar.gz` und ein zusätzliches ZIP-Archiv,
6. signiert das `tar.gz` separat für den Nextcloud App Store,
7. veröffentlicht alle Dateien mit SHA-256-Prüfsummen im GitHub-Release und
8. veröffentlicht stabile Versionen optional direkt im Nextcloud App Store.

Die Einrichtung der drei benötigten Secrets und der genaue Bedienungsweg sind
in [`.github/RELEASING.md`](.github/RELEASING.md) dokumentiert.

## Lizenz

AGPL-3.0-or-later
