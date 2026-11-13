# Cache Monitor für Unraid

Plugin für die Cache-Pools. Es zeigt, wie voll der Cache ist, welche Ordner und Dateien den Platz belegen, ob er vollläuft, und wo in 24 Stunden und 7 Tagen etwas dazu- oder weggekommen ist.

Zusätzlich: Btrfs-Daten und Metadaten, RAID-Profil, Geräte und Temperatur, Inodes, Scrub, Cache-Floor, Shares mit Cache-Modus, Docker-Image und VM-Abbilder, Verlauf über 30 Tage, Unraid-Benachrichtigung.

Die Seite liegt unter **Einstellungen → Benutzer-Utilities (User Utilities) → Cache Monitor**. Dieselbe Seite öffnet auch die Plugin-Liste.

## Installieren

In Unraid: **Plugins → Install Plugin**, diese Adresse einfügen und **Install** wählen:

```text
https://raw.githubusercontent.com/PaulG67/unraid-cache-monitor/main/cache-monitor.plg
```

Danach **Einstellungen → Benutzer-Utilities (User Utilities) → Cache Monitor** öffnen und **Jetzt messen** wählen. Ordner auf einem großen Cache können einige Minuten brauchen. Danach die Seite neu laden. Die stündliche Messung läuft zusätzlich von selbst.

Es ist kein Docker-Container und kein User Script nötig. Schwellen und der Verlauf liegen auf dem Flash unter `/boot/config/plugins/cache-monitor/` und bleiben bei einem Neustart erhalten.

## Aktualisieren

**Plugins → Check for Updates**, dann das angebotene Update installieren. Einstellungen und Verlauf bleiben.

Wenn Unraid kein Update anzeigt, die Seite mit Strg+F5 neu laden. Die Version steht unten auf der Cache-Monitor-Seite und im Plugin als `2026.11.13.2`.

## Was die Messung macht

- Jede Stunde: Füllstand, frei, Temperatur, Dateisystem, Prognose. Das ist ein kurzer Lauf.
- Alle 6 Stunden, und über **Jetzt messen**: Ordner bis eine Ebene tiefer, Dateien ab 512 MB, Docker und VM-Abbilder. Das liest den Cache und kann dauern.
- Ab 80 % Belegung, knappen Btrfs-Metadaten oder einer Volllauf-Prognose innerhalb von 14 Tagen kommt eine Unraid-Benachrichtigung. Dieselben Schwellen lassen sich auf der Seite ändern.

Ordnergrößen kommen von `du`. Bei Kompression, Snapshots oder RAID kann die Summe anders aussehen als der Füllstand von `df`. Für „ist noch Platz zum Schreiben“ gilt die Anzeige oben.
