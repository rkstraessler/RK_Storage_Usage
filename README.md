# Storage Usage API

Öffentlicher, schreibgeschützter Nextcloud-Endpunkt für die summierte
Speichernutzung aller bekannten Benutzer.

## Endpoint

```text
/index.php/apps/storageusage/api/v1/usage
```

## Response

```json
{
    "totalUsage": 3596755985
}
```

`totalUsage` wird in Bytes ausgegeben.

## Cache

- Interner Nextcloud-Local-Cache
- TTL: 60 Sekunden
- Bei konfiguriertem `memcache.local` wird APCu verwendet
- Der erste Request nach Ablauf berechnet den Wert neu
- Weitere Requests innerhalb von 60 Sekunden lesen nur den Cache
- Es wird kein rekursiver Festplatten-Scan ausgeführt
