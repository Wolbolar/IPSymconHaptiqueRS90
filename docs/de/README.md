# Haptique RS90 Integration for IP-Symcon
[![Version](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Version](https://img.shields.io/badge/Symcon%20Version-%3E%209.0-green.svg)](https://www.symcon.de/service/dokumentation/installation/)

Modul für IP-Symcon ab Version 9.0 zur Integration der **Cantata Haptique RS90 / RS90x AV Remote** in IP-Symcon.

Das Modul stellt eine Integration zwischen IP‑Symcon und der Haptique Plattform bereit. Geräte aus IP‑Symcon können auf der RS90 Remote angezeigt und gesteuert werden. Gleichzeitig können Aktionen der Remote in IP‑Symcon verarbeitet werden.

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Funktionsreferenz](#4-funktionsreferenz)
5. [Konfiguration](#5-konfiguration)
6. [Anhang](#6-anhang)

## 1. Funktionsumfang

Das Modul integriert die **Cantata Haptique RS90 / RS90x Remote** in IP‑Symcon.

Es ermöglicht:

- Bereitstellung von Symcon Geräten für die Haptique Remote
- Steuerung von Symcon Geräten über die Remote
- Rückmeldung von Gerätestatus an die Remote
- Geräte‑Discovery innerhalb von IP‑Symcon
- Mapping von Symcon Geräten auf Haptique Device‑Typen
- Unterstützung verschiedener Geräteklassen

Beispiele unterstützter Geräte:

- Licht
- Dimmer
- RGB / RGBW Licht
- Steckdosen
- Schalter
- Media Player
- AV Geräte
- Szenen
- Selektoren

Das Modul fungiert dabei als **Integration Driver** zwischen IP‑Symcon und der Haptique Plattform.

## 2. Voraussetzungen

- IP‑Symcon >= 9.0 (IPSModuleStrict erforderlich)
- Cantata Haptique RS90 / RS90x
- Haptique OS Server
- Netzwerkverbindung zwischen Remote und IP‑Symcon

## 3. Installation

### a. Installation des Moduls

Die WebConsole von IP‑Symcon öffnen:

```
http://{IP-Symcon-IP}:3777/console/
```

Danach das Modul aus dem entsprechenden Repository installieren oder manuell hinzufügen.

### b. Modul hinzufügen

Nach der Installation kann eine Instanz des **Haptique Integration Drivers** erstellt werden.

Diese Instanz stellt die Verbindung zwischen IP‑Symcon und der Haptique Remote her.

### c. Verbindung zur Remote herstellen

Die Remote bzw. der Haptique Server kann den Integration Driver automatisch über **mDNS** erkennen oder manuell registriert werden.

Nach erfolgreicher Verbindung können Geräte aus IP‑Symcon für die Remote bereitgestellt werden.

## 4. Funktionsreferenz

### Geräte Discovery

Das Modul durchsucht den Symcon Objektbaum nach kompatiblen Geräten und bietet diese zur Auswahl an.

Unterstützt werden unter anderem:

- Licht
- Schalter
- Steckdosen
- Dimmer
- Mediengeräte

### Statusübertragung

Änderungen von Geräten in IP‑Symcon werden automatisch an die Remote übertragen.

Dadurch kann der aktuelle Status direkt auf der Remote angezeigt werden.

### Steuerung

Befehle von der Remote werden an das entsprechende Gerät in IP‑Symcon weitergeleitet.

## 5. Konfiguration

### Integration Driver

| Eigenschaft | Typ | Beschreibung |
|-------------|-----|-------------|
| Port | integer | Port des Integration Drivers |
| Token | string | Optionaler Authentifizierungs‑Token |
| Whitelist | string | Liste erlaubter IP‑Adressen |

### Geräte Mapping

Innerhalb der Modulkonfiguration können Symcon Geräte verschiedenen Haptique Gerätetypen zugeordnet werden.

Beispielsweise:

- Symcon Licht → Haptique Light
- Symcon Media Player → Haptique Media Player

## 6. Anhang

### a. Architektur

Das Modul implementiert einen **Haptique Integration Driver** für IP‑Symcon.

Der Driver übernimmt:

- Registrierung bei der Remote
- Bereitstellung von Geräten
- Verarbeitung eingehender Befehle
- Statusupdates

### b. GUIDs und Datenaustausch

Integration Driver GUID:

```
{D5CC2243-C3B3-4C0D-1E07-81701A5FE120}
```

(Diese wird automatisch vom Modul definiert.)