# Storage Usage API

Storage Usage API ist eine kleine Nextcloud-App, die die summierte
Speichernutzung aller bekannten Benutzer als JSON bereitstellt.

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
    "totalUsage": 3.35,
    "unit": "GiB",
    "totalUsageBytes": 3596755985,
    "cacheTtl": 60
}
```

Die Ausgabeeinheit für `totalUsage` kann im Nextcloud-Adminbereich eingestellt
werden. `totalUsageBytes` enthält unabhängig davon immer den exakten Wert in
Bytes.

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

## Datenschutz und Sicherheit

Der Endpoint ist bewusst **öffentlich und ohne Anmeldung erreichbar**. Er gibt
nur die Gesamtsumme zurück. Benutzernamen, einzelne Verbrauchswerte,
Dateinamen und Dateiinhalte werden nicht ausgegeben.

Wer die Gesamtsumme nicht öffentlich bereitstellen möchte, darf die App nicht
aktivieren oder muss den Endpoint zusätzlich über den vorgeschalteten
Webserver schützen.

## Cache

- Interner Nextcloud-Local-Cache
- Einstellbare TTL: 0 bis 3600 Sekunden
- Bei konfiguriertem `memcache.local` wird beispielsweise APCu verwendet
- Der erste Request nach Ablauf berechnet den Wert neu
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
