# Automatische Releases

Der Workflow `Build, sign and publish release` baut und signiert einen bereits
veröffentlichten GitHub-Release der App `storageusage`. Der Release-Tag ist die
verbindliche Versionsangabe: Aus `v1.0.3` wird automatisch die App-Version
`1.0.3`.

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

Für das Environment `release` sind eine erforderliche Freigabe und passende
Deployment-Regeln empfehlenswert. Dabei müssen Tags wie `v*` für automatische
Läufe und `main` für den manuellen Wiederholungslauf erlaubt sein. Dann gelangt
nur ein bestätigter Release an die Signaturzertifikate.

## Release starten

1. Zuerst alle gewünschten Änderungen nach `main` pushen.
2. In GitHub `Releases → Draft a new release` öffnen.
3. Einen neuen Tag im Format `vMAJOR.MINOR.PATCH` anlegen, zum Beispiel
   `v1.0.3`, und als Ziel den aktuellen `main` auswählen.
4. Titel, Release-Text und optional das REIKEM-Banner eintragen.
5. `Publish release` anklicken.

Das Veröffentlichen startet den Workflow automatisch. Er übernimmt `1.0.3`
aus `v1.0.3`, baut die App und ergänzt den vorhandenen GitHub-Release um:

- `storageusage-v1.0.3.tar.gz` – signiertes App-Store-Paket
- `storageusage-v1.0.3.zip` – signiertes ZIP für manuelle Installationen
- `storageusage-v1.0.3.signature` – separate SHA-512-App-Store-Signatur
- `storageusage-v1.0.3.sha256` – SHA-256-Prüfsummen aller Artefakte

Stabile Releases werden anschließend automatisch an den Nextcloud App Store
übermittelt. GitHub-Prereleases und Versionen mit SemVer-Suffix wie
`v2.0.0-beta.1` werden gebaut, aber nicht als stabile App-Store-Version
veröffentlicht.

## Fehlgeschlagenen oder bestehenden Release wiederholen

Ein bereits veröffentlichter Release löst nach einer späteren Änderung des
Workflows kein zweites `published`-Ereignis aus. Falls `v1.0.3` bereits
veröffentlicht wurde und noch samt Tag vorhanden ist, muss dieser Lauf daher
einmal manuell wiederholt werden.

Für eine Wiederholung:

1. `Actions → Build, sign and publish release` öffnen.
2. `Run workflow` auswählen.
3. Den vorhandenen Release-Tag, beispielsweise `v1.0.3`, eintragen.
4. `Run workflow` anklicken.

Der manuelle Lauf verwendet denselben Workflow mit dem Quellstand des Tags.
Vorhandene Artefakte
werden bytegenau verglichen. Fehlende Dateien werden hochgeladen; abweichende
bereits veröffentlichte Dateien führen bewusst zu einem Fehler und werden
nicht unbemerkt überschrieben.

## Verhalten von Tag und App-Version

Der Workflow verschiebt oder überschreibt niemals einen bestehenden Git-Tag.
Wenn der Commit hinter `v1.0.3` in `appinfo/info.xml` noch `1.0.2` enthält,
setzt der Runner die Paketversion ausschließlich in seinem temporären
Build-Arbeitsbaum auf `1.0.3`. Das signierte `tar.gz` und ZIP enthalten damit
die korrekte Version `1.0.3`.

Die von GitHub automatisch angebotenen Dateien `Source code (zip)` und
`Source code (tar.gz)` sind dagegen unveränderliche Abbilder des Tag-Commits
und können weiterhin `1.0.2` enthalten. Für Nextcloud muss deshalb immer das
signierte Asset `storageusage-v1.0.3.tar.gz` verwendet werden.

Für zukünftige Releases ist es übersichtlicher, `appinfo/info.xml` und den
Abschnitt `Unreleased` im Changelog bereits vor dem Tag auf die gewünschte
Version zu bringen. Technisch notwendig ist das nicht: Der Tag bleibt für den
Release-Build die maßgebliche Versionsangabe.

## Sicherheit

Die Schlüssel werden nur im kurzlebigen GitHub-Runner materialisiert und am
Ende des Jobs entfernt. Sie werden weder in das App-Paket noch in Git-Commits
aufgenommen. Der Workflow prüft außerdem, dass privater Schlüssel, Zertifikat
und Zertifikats-CN zur App-ID `storageusage` gehören. Der Signier-Container ist
auf einen unveränderlichen Image-Digest festgelegt, hat keinen Netzwerkzugang,
und der Workflow weist jede unerwartete Änderung an den App-Dateien zurück.

Der Workflow akzeptiert nur SemVer-Tags mit führendem `v`, die von `main`
erreichbar sind. Ein Prerelease-Suffix ist erlaubt, Build-Metadaten mit `+`
sind nicht vorgesehen. Er pusht weder Commits noch Tags und verändert den
vorhandenen Release-Text nicht.

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
