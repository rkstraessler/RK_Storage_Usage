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
    "totalUsage": 3596755985
}
```

`totalUsage` wird in Bytes ausgegeben.

## Datenschutz und Sicherheit

Der Endpoint ist bewusst **öffentlich und ohne Anmeldung erreichbar**. Er gibt
nur die Gesamtsumme zurück. Benutzernamen, einzelne Verbrauchswerte,
Dateinamen und Dateiinhalte werden nicht ausgegeben.

Wer die Gesamtsumme nicht öffentlich bereitstellen möchte, darf die App nicht
aktivieren oder muss den Endpoint zusätzlich über den vorgeschalteten
Webserver schützen.

## Cache

- Interner Nextcloud-Local-Cache
- TTL: 60 Sekunden
- Bei konfiguriertem `memcache.local` wird beispielsweise APCu verwendet
- Der erste Request nach Ablauf berechnet den Wert neu
- Weitere Requests innerhalb von 60 Sekunden lesen nur den Cache
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

## Lizenz

AGPL-3.0-or-later
