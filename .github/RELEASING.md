# Automatische Releases

Der Workflow `Build, sign and publish release` erstellt aus einer einzigen
Versionsangabe einen vollständigen und signierten Release der App
`storageusage`.

## Einmalige GitHub-Einrichtung

Im Repository unter `Settings → Environments` ein Environment mit dem Namen
`release` erstellen. Darin unter `Environment secrets` diese drei Secrets
anlegen:

| Secret | Inhalt |
| --- | --- |
| `APP_PRIVATE_KEY` | Vollständiger PEM-Inhalt von `storageusage.key` einschließlich `BEGIN`- und `END`-Zeile |
| `APP_PUBLIC_CRT` | Vollständiger PEM-Inhalt von `storageusage.crt` einschließlich `BEGIN`- und `END`-Zeile |
| `APPSTORE_TOKEN` | API-Token des eigenen Kontos auf `apps.nextcloud.com` |

Der App-Store-Token ist nach der Anmeldung unter
<https://apps.nextcloud.com/account/token> erreichbar. `GITHUB_TOKEN` wird von
GitHub automatisch bereitgestellt und darf nicht als eigenes Secret angelegt
werden.

Optional kann für das Environment `release` eine erforderliche Freigabe
konfiguriert werden. Dann wartet jeder Produktions-Release vor dem Zugriff auf
die Zertifikate auf eine Bestätigung.

Der Workflow erstellt den Versions-Commit direkt auf dem Standard-Branch. Eine
Regel wie `Require a pull request before merging` blockiert diesen Push auch
für den eingebauten `GITHUB_TOKEN`. Für den hier beschriebenen Ein-Klick-Ablauf
muss `main` daher direkte Workflow-Pushes erlauben. Soll die Pull-Request-Regel
unverändert bleiben, wäre stattdessen eine eigene GitHub App mit Schreibrecht
und Ruleset-Bypass nötig; deren Installationstoken müsste der Workflow anstelle
des eingebauten Tokens verwenden.

## Release starten

1. GitHub öffnen und `Actions` auswählen.
2. `Build, sign and publish release` auswählen.
3. `Run workflow` öffnen und als Branch `main` beibehalten.
4. Die neue Version ohne führendes `v` eintragen, zum Beispiel `2.0.0`.
5. `Publish stable releases to the Nextcloud App Store` aktiviert lassen.
6. `Run workflow` anklicken.

Der Workflow erhöht die Version in `appinfo/info.xml`, ergänzt automatisch
`CHANGELOG.md`, erstellt den Commit `chore(release): prepare v2.0.0`, pusht das
Tag `v2.0.0` und veröffentlicht:

- `storageusage-v2.0.0.tar.gz` – signiertes App-Store-Paket
- `storageusage-v2.0.0.zip` – signiertes ZIP für manuelle Installationen
- `storageusage-v2.0.0.signature` – separate SHA-512-App-Store-Signatur
- `storageusage-v2.0.0.sha256` – SHA-256-Prüfsummen aller Artefakte

Der Release-Text enthält automatisch das REIKEM-Release-Banner und die Commits
seit dem vorherigen Tag. Versionen mit einem SemVer-Suffix, beispielsweise
`2.1.0-beta.1`, werden als GitHub-Prerelease gebaut und nicht automatisch als
stabile Version in den Nextcloud App Store geladen.

## Sicherheit und Wiederholung

Die Schlüssel werden nur im kurzlebigen GitHub-Runner materialisiert und am
Ende des Jobs entfernt. Sie werden weder in das App-Paket noch in Git-Commits
aufgenommen. Der Workflow prüft außerdem, dass privater Schlüssel, Zertifikat
und Zertifikats-CN zur App-ID `storageusage` gehören. Der Signier-Container ist
auf einen unveränderlichen Image-Digest festgelegt, hat keinen Netzwerkzugang,
und der Workflow weist jede unerwartete Änderung an den App-Dateien zurück.

Scheitert lediglich die App-Store-Veröffentlichung nach einem erfolgreichen
GitHub-Release, kann der Workflow mit derselben Version erneut gestartet
werden. Er vergleicht dann die bereits veröffentlichten Artefakte bytegenau
mit dem reproduzierten Build und versucht anschließend die Veröffentlichung
erneut. Bereits veröffentlichte Dateien werden nicht unbemerkt überschrieben.

Die lokal über Docker Desktop laufende Nextcloud bleibt für manuelle Tests
erhalten. GitHub Actions greift nicht auf den lokalen Rechner zu, sondern
verwendet für die Integritätssignatur einen eigenen kurzlebigen
`nextcloud:34.0.2-apache`-Container.

Der Release-Build kann unter Windows mit der bestehenden Docker-Desktop- und
Ubuntu-WSL-Installation ohne Veröffentlichung vollständig geprüft werden:

```powershell
.\scripts\test-release-windows.ps1
```

Der private Schlüssel wird dabei direkt von WSL in den kurzlebigen
Signier-Container gestreamt und nicht in das Windows-Temp-Verzeichnis kopiert.
Staging-App und Archive liegen in einem eindeutig benannten temporären
Verzeichnis und werden anschließend entfernt.
