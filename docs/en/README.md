# Haptique RS90 Integration for IP-Symcon
[![Version](https://img.shields.io/badge/Symcon-PHPModule-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Version](https://img.shields.io/badge/Symcon%20Version-%3E%209.0-green.svg)](https://www.symcon.de/en/service/documentation/installation/)

Module for **IP‑Symcon version 9.0 or higher** to integrate the **Cantata Haptique RS90 / RS90x AV Remote** into IP‑Symcon.

This module acts as an **Integration Driver** between the Haptique ecosystem and IP‑Symcon. Devices managed by IP‑Symcon can be exposed to the Haptique platform and controlled from the RS90 remote. At the same time, commands triggered on the remote can be processed inside IP‑Symcon.

## Documentation

**Table of Contents**

1. [Features](#1-features)
2. [Requirements](#2-requirements)
3. [Installation](#3-installation)
4. [Function Reference](#4-function-reference)
5. [Configuration](#5-configuration)
6. [Annex](#6-annex)

---

# 1. Features

This module integrates the **Cantata Haptique RS90 / RS90x Remote** with IP‑Symcon.

Main capabilities:

- Provide IP‑Symcon devices to the Haptique platform
- Control Symcon devices from the RS90 remote
- Receive and process commands sent by the remote
- Automatic device discovery inside the Symcon object tree
- Mapping of Symcon devices to Haptique device types
- Bidirectional status synchronization

Supported device examples include:

- Lights
- Dimmers
- RGB / RGBW lighting
- Switches
- Smart plugs
- Media players
- AV devices
- Scenes
- Select / mode devices

The module acts as a **bridge between IP‑Symcon and the Haptique control ecosystem**.

---

# 2. Requirements

- **IP‑Symcon ≥ 9.0** (IPSModuleStrict based module)
- Cantata **Haptique RS90 / RS90x Remote**
- Haptique OS Server
- Network connectivity between the remote and IP‑Symcon

---

# 3. Installation

## a. Install the module

Open the IP‑Symcon WebConsole:

```
http://{IP-Symcon-IP}:3777/console/
```

Install the module from the repository or add it manually via the module loader.

## b. Create the module instance

After installation create an instance of the **Haptique Integration Driver**.

This instance provides the interface between IP‑Symcon and the Haptique platform.

## c. Connect the remote

The RS90 remote or the Haptique OS server can discover the integration driver automatically via **mDNS** or it can be registered manually.

After a successful connection the available IP‑Symcon devices can be exposed to the Haptique platform.

---

# 4. Function Reference

## Device Discovery

The module scans the Symcon object tree and detects compatible devices automatically.

Supported device categories include:

- Lights
- Switches
- Smart plugs
- Dimmers
- Media players
- AV devices

Detected devices can then be mapped to corresponding **Haptique device types**.

## Status Synchronization

Changes to device states inside IP‑Symcon are automatically transmitted to the remote.

This allows the RS90 interface to always display the current device state.

## Remote Control

Commands issued on the remote are forwarded to the appropriate device in IP‑Symcon.

---

# 5. Configuration

## Integration Driver Settings

| Property | Type | Description |
|--------|------|-------------|
| Port | integer | Port used by the integration driver |
| Token | string | Optional authentication token |
| Whitelist | string | Allowed client IP addresses |

## Device Mapping

Within the module configuration Symcon devices can be mapped to Haptique device types.

Examples:

- Symcon Light → Haptique Light
- Symcon Media Player → Haptique Media Player
- Symcon Switch → Haptique Switch

---

# 6. Annex

## a. Architecture

The module implements a **Haptique Integration Driver for IP‑Symcon**.

Responsibilities of the driver:

- Registration with the Haptique platform
- Providing Symcon devices to the remote
- Handling commands sent by the remote
- Synchronizing device states

Architecture overview:

```
Haptique RS90 Remote
        │
   Haptique OS
        │
Integration Driver (IP‑Symcon Module)
        │
    IP‑Symcon Devices
```

## b. GUIDs and Data Exchange

Integration Driver GUID:

```
{D5CC2243-C3B3-4C0D-1E07-81701A5FE120}
```

(The actual GUID is defined inside the module.)