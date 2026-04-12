# RS90 Home Assistant Emulation Plan

Stand: 2026-04-10

## Zwischenstand Umbau 2026-04-10

Erster Auslagerungsschritt umgesetzt:

- `libs/HaptiqueHttpResponse.php` angelegt fuer kleine HTTP-Response-Objekte.
- `libs/HomeAssistantEntityRepository.php` angelegt. Baut aktuell HA-States und eine Geraeteliste aus den vorhandenen Konfigurator-/Child-Daten.
- `libs/HomeAssistantServiceExecutor.php` angelegt. Fuehrt aktuell `light.turn_on`, `light.turn_off`, `switch.turn_on`, `switch.turn_off` und `automation.trigger` aus.
- `libs/HomeAssistantEmulator.php` angelegt. Uebernimmt das Routing fuer `GET /api/states`, `GET /api/states/<entity_id>`, `POST /api/services/<domain>/<service>` und `POST /api/template`.
- `Haptique Splitter/module.php` bindet den Emulator ein und delegiert den produktiven Pfad in `sendHomeAssistantResponse()` an die neue Klasse.
- Die alte Inline-Logik im Splitter ist noch als Referenz im File vorhanden, wird durch den fruehen Return in `sendHomeAssistantResponse()` aber nicht mehr genutzt.
- `libs/HaptiqueMdnsDiscovery.php` angelegt. Registriert den bestehenden Cantata-Dienst und optional zusaetzlich einen `_home-assistant._tcp` Dienst mit dem Namen `Symcon Home Assistant`.
- Im Splitter wurde die Property `EmulateHomeAssistantDiscovery` ergaenzt. Sie ist standardmaessig aktiv und im Formular als Checkbox sichtbar.
- Debug-Logging fuer den HA-Pfad wurde erweitert:
  - `HA`: HTTP-Request, Route, vorbereitete Response.
  - `IO`: Socket-Eingang und gesendete HTTP-Antwort.
  - `AUTH`: Bearer-Token gefunden/ungueltig/fehlend.
  - `ENTITY`: erzeugte HA-Entities, States und Device-Listen.
  - `CMD`: HA-Service-Aufrufe und Symcon-Aktionen.
  - `DISCOVERY`: mDNS-Registrierung.
- `ReceiveData()` schreibt `ClientIP` und `ClientPort` jetzt vor `handleRequest()`. Vorher konnte die erste HA-HTTP-Antwort an eine leere oder alte Client-Adresse gehen.
- `ReceiveData()` behandelt den IO-`Buffer` fuer `IPSModuleStrict` jetzt strikt als Hex und dekodiert mit `hex2bin()`. Die vorherige UTF-8/ISO-8859-1-Konvertierung wurde entfernt.
- Die Haptique Config App sendete im Test bei `GET /api/states` keinen `Authorization: Bearer` Header, sondern nur ein `connect.sid` Cookie. Fehlende Bearer-Tokens werden fuer den HA-Emulationspfad deshalb vorerst nur als Warnung geloggt und nicht mehr mit `401` geblockt.
- Im Test war die Splitter-Property `Token` leer, obwohl der Token in der App korrekt hinterlegt war. Das war ein lokaler Konfigurationszustand; die temporaere Lockerung fuer "Bearer vorhanden, Splitter-Token leer" wurde wieder entfernt.
- `HA_GetConfiguratorData()` nutzt zuerst den bestehenden Child-Request (`SendDataToChildren`). Falls kein Child antwortet, liest es als Fallback die Properties der verbundenen `Haptique Import Configurator`-Instanz direkt. Hintergrund: Im Test kam `GET /api/states` korrekt an, aber die Entity-Liste blieb leer, weil `SendDataToChildren()` `[]` lieferte.
- HTTP-Requests vom ServerSocket werden nicht mehr pauschal an Children gebroadcastet. Das hatte dazu gefuehrt, dass der `Haptique Import Configurator` den kompletten `GET /api/states` Request als unbekannten `Buffer` erhielt, obwohl er nur `GetLights`, `GetSwitches` usw. beantworten soll.
- Die Import-Configurator-Fallback-Suche wurde entschärft: Wenn genau ein Import Configurator existiert, wird er als Fallback genutzt, auch wenn die Parent-Verknuepfung ueber `IPS_GetInstance()` nicht eindeutig erkennbar ist. In diesem Fall wird eine Warnung mit Kandidatenliste geloggt.
- Die Kommunikation `Splitter -> ServerSocket` wurde nach der Umstellung auf `IPSModuleStrict` zentralisiert: HTTP- und WebSocket-Antworten laufen jetzt ueber `SendToServerSocket()`. Dort wird der komplette Wire-Payload per `bin2hex()` als `Buffer` an den Parent geschickt und `Type = 0` gesetzt. Erwartung fuer den naechsten Test: Nach `📤 Sending payload to ServerSocket` im Splitter muss im ServerSocket ein ausgehender `TRANSMIT`/Sendeeintrag an denselben Client-Port sichtbar werden.
- WebSocket-Fix nach erstem RS90-Steuertest: Der Splitter hat nach `GET /api/websocket` zuerst korrekt `101 Switching Protocols`, danach aber faelschlich noch eine `404`-HTTP-Antwort fuer dieselbe Anfrage gesendet. `processReceivedData()` beendet den Pfad nach dem Handshake jetzt sofort. Zusaetzlich werden WebSocket-Frames nicht mehr in den HTTP-ReceiveBuffer geschrieben, sondern separat dekodiert.
- Minimaler Home-Assistant-WebSocket-Dialog ist ergaenzt: `auth_required`, `auth`, `get_states`, `subscribe_events`, `ping` und `call_service`. `call_service` nutzt den bestehenden HA-Service-Executor und sendet danach ein `state_changed` Event an die abonnierte RS90-Verbindung.
- Zweiter RS90-Steuertest mit `switch`: Die RS90 sendet den Schaltbefehl nicht per WebSocket, sondern als REST-Call `POST /api/services/switch/turn_on` mit `{"entity_id":"switch.14753"}`. Die Antwort wurde von `{"success":true}` auf eine Home-Assistant-naehere Liste der geaenderten States umgestellt. REST-Service-Calls senden zusaetzlich `state_changed` Events an vorhandene WebSocket-Subscriptions, falls die RS90 eine solche Verbindung offen haelt.
- ServerSocket zeigte beim REST-Service-Call zweimal denselben `TRANSMIT`. Eine kurz getestete Dedupe-Sperre im Sendepfad wurde wieder entfernt, weil sie legitime Antworten blockieren konnte. Stattdessen wird vor dem eigentlichen Senden `📤 Preparing HA HTTP response wire` geloggt, damit der Pfad `Response prepared -> wire built -> SendToServerSocket -> ServerSocket TRANSMIT` eindeutig nachvollziehbar ist.
- Android-Logcat gegen echten Home Assistant zeigte, dass die RS90 die UI offenbar ueber Home-Assistant-`subscribe_trigger` Events aktualisiert. Das Event ist nicht das normale `event.data.old_state/new_state` Format, sondern `event.variables.trigger.from_state/to_state`. Der WebSocket-Handler unterstuetzt jetzt `subscribe_trigger` und sendet fuer REST-Service-Aenderungen passende `HA WS trigger event` Nachrichten. HA-State-Responses enthalten zusaetzlich `last_reported`.
- RS90 oeffnet parallele TCP-Verbindungen: Im Test kam `GET /api/websocket` auf Port `33046`, direkt danach `GET /api/states/switch.14753` auf Port `33048`. Der Splitter nutzte vorher globale Attribute `ClientIP/ClientPort` und einen globalen `ReceiveBuffer`; dadurch wurde `auth_required` teils an den falschen Port geschickt. HTTP-Buffer und Antwortadresse sind jetzt pro Client-Kontext (`IP:Port`) gefuehrt.
- ServerSocket-Dump vom 2026-04-11 zeigte doppelte Antworten. Das wurde spaeter auf eine ueberfluessige zweite Splitter-Instanz zurueckgefuehrt; die zweite Instanz wurde geloescht. Aktuell gibt es nur noch einen relevanten Splitter.

Noch nicht geloest:

- Praktisch verifizieren, ob die RS90 nach dem WebSocket-Handshake die HA-WebSocket-Auth akzeptiert und `subscribe_events` sendet. Fuer UI-Live-Updates ist wichtig, dass nach einem `call_service` ein `state_changed` Event mit der Subscription-ID der RS90 gesendet wird.
- Media-Player-Services liefern noch `501 Not Implemented`, weil die bisherigen Media-Player-Aktionen hart codierte Testmappings verwendet haben.
- `remote` ist noch nicht implementiert, bis die echten RS90-Service-Aufrufe bekannt sind.
- Die HA-Discovery muss mit der RS90 Config App praktisch verifiziert werden. Insbesondere ist offen, ob die App die gesetzten TXT-Records akzeptiert oder weitere Home-Assistant-spezifische Felder erwartet.
- Die grosse Alt-Fixture `GetCompleteHAResponseComplete()` ist noch im Splitter und sollte spaeter verschoben oder entfernt werden.

## Ziel

Symcon soll fuer die Cantata Haptique RS90 uebergangsweise wie ein Home-Assistant-System wirken, damit die RS90 und die Haptique Config App die bereits vorhandenen Home-Assistant-UIs verwenden koennen. Symcon soll dabei nur die minimal noetigen Home-Assistant-API-Antworten liefern, nicht mehr einen kompletten echten Home-Assistant-State-Dump.

Langfristig soll die Emulation so gekapselt sein, dass spaeter eine native Cantata/Symcon-Integration an derselben Geraete- und Befehlslogik andocken kann.

## Aktueller Stand

- Das relevante Modul liegt unter `Haptique Splitter`.
- Der Splitter registriert einen Webhook `cantata` und verarbeitet zusaetzlich Daten vom Parent/ServerSocket ueber `ReceiveData()`.
- `EmulateHomeAssistant` ist bereits als Property vorhanden und standardmaessig aktiv.
- `ReceiveData()` sammelt Socket-Daten in `ReceiveBuffer`, ruft `processReceivedData()` auf und leitet Rohdaten an Children weiter.
- `processReceivedData()` erkennt einen WebSocket-Upgrade auf `/api/websocket`, prueft `Authorization: Bearer <Token>` und ruft je nach Property `sendHomeAssistantResponse()` oder `sendIPSymconResponse()` auf.
- `sendHomeAssistantResponse()` routet HTTP-Requests derzeit direkt im Splitter, unter anderem:
  - `GET /api/states`
  - `GET /api/states/<entity_id>`
  - `POST /api/services/light/turn_on`
  - `POST /api/services/light/turn_off`
  - `POST /api/services/switch/turn_on`
  - `POST /api/services/switch/turn_off`
  - `POST /api/services/automation/trigger`
  - `POST /api/services/media_player/media_play`
  - `POST /api/services/media_player/media_pause`
  - `POST /api/services/media_player/media_next_track`
  - `POST /api/services/media_player/media_previous_track`
  - `POST /api/template`
- `GET /api/states` ruft aktuell `GetCompleteHAResponse()` auf. Diese Methode baut schon eine dynamische State-Liste aus den Child-/Configurator-Daten auf.
- Es existiert zusaetzlich `GetCompleteHAResponseComplete()`, das einen sehr grossen echten Home-Assistant-Dump enthaelt und als Alt-/Testdaten betrachtet werden sollte.
- `handlePostTemplate()` liefert aktuell noch eine hart codierte Geraeteliste mit Testgeraeten.
- Mehrere Service-Ausfuehrungen enthalten noch hart codierte IDs oder unvollstaendige Mappings, besonders Media Player.
- Es gibt eine aeltere DeviceType-/Capability-Registry unter `Haptique Splitter/types`, `capabilities`, `helper` und `registry.php`. Diese Includes sind im Splitter aktuell auskommentiert und die Struktur ist damit nicht aktiv.
- Die mDNS-Registrierung nutzt aktuell `_cantata-integration._tcp`, die Deregistrierung filtert aber `_uc-integration._tcp`. Das ist inkonsistent. Fuer HA-Emulation muss zusaetzlich bzw. alternativ `_home-assistant._tcp` geprueft werden.
- Die Remote-3-Vorlage enthaelt mit `libs/DnssdRemoteDiscoveryTrait.php` ein brauchbares Beispiel fuer DNS-SD/mDNS-Abfragen und kann als Referenz fuer saubere Discovery-Integration dienen.

## Ziel-Geraetetypen fuer die RS90-HA-Emulation

Diese Typen sollen als Home-Assistant-Entities aus Symcon abgebildet werden:

- `automation`: Start eines Symcon-Skripts oder Ablaufplans.
- `light`: Schalten, spaeter Dimmen/Farbe/Farbtemperatur je nach Symcon-Variablen.
- `media_player`: Wiedergabe, Pause, Next, Previous, Lautstaerke, Mute und Status soweit vorhanden.
- `remote`: Remote-Kommandos bzw. Sequenzen/Kommandos als HA-Remote-Services.
- `sensor`: Temperatur, Batterie, Bewegung/Binary Sensor, Helligkeit und weitere read-only Werte.
- `switch`: Boolean-Schalter.

## Vorgeschlagene Architektur

1. Eine kleine HA-Emulationsschicht aus dem grossen Splitter herausziehen, z.B. `Haptique Splitter/helper/homeAssistantEmulator.php` oder `libs/HomeAssistantEmulator.php`.
2. HTTP-Parsing, Routing, State-Erzeugung und Service-Ausfuehrung logisch trennen:
   - Request: Methode, Pfad, Header, Body, Token.
   - Router: HA-Endpunkte und Service-Namen.
   - Entity Repository: liest Konfigurator-/Child-Daten und erzeugt eine einheitliche interne Entity-Liste.
   - HA Serializer: erzeugt minimale HA-kompatible JSON-Antworten.
   - Action Executor: fuehrt Symcon-Aktionen aus.
3. Eine zentrale Entity-ID-Konvention verwenden:
   - Technisch stabil und eindeutig, z.B. `<domain>.<symcon_object_id>` oder `<domain>.ips_<object_id>`.
   - Keine zufaelligen Namen aus Anzeigenamen ableiten, ausser die RS90 verlangt das.
   - Mapping zwischen Entity-ID und Symcon-Zielobjekt zentral halten.
4. `GET /api/states` nur noch mit den freigegebenen RS90-/Symcon-Entities beantworten.
5. `GET /api/states/<entity_id>` aus derselben Entity-Liste beantworten, nicht mit separaten Spezialpfaden.
6. `POST /api/services/<domain>/<service>` generisch parsen und an den passenden Executor delegieren.
7. Alte Testdaten und hart codierte IDs nur noch als Testfixture oder Referenz behalten, nicht im Produktivpfad.

## Minimal noetige HA-Endpunkte, die zuerst stabil werden sollten

Prioritaet 1:

- `GET /api/states`
- `GET /api/states/<entity_id>`
- `POST /api/services/light/turn_on`
- `POST /api/services/light/turn_off`
- `POST /api/services/switch/turn_on`
- `POST /api/services/switch/turn_off`
- `POST /api/services/automation/trigger`

Prioritaet 2:

- `POST /api/services/media_player/media_play`
- `POST /api/services/media_player/media_pause`
- `POST /api/services/media_player/media_next_track`
- `POST /api/services/media_player/media_previous_track`
- `POST /api/services/media_player/volume_set`
- `POST /api/services/media_player/volume_mute`

Prioritaet 3:

- `remote`-Services anhand realer RS90-Anfragen klaeren, z.B. `remote.send_command`, `remote.turn_on`, `remote.turn_off`.
- `POST /api/template` nur behalten, falls die RS90 oder App ihn wirklich fuer die Geraeteliste nutzt.
- Discovery ueber `_home-assistant._tcp` implementieren oder parallel zu `_cantata-integration._tcp` registrieren, sobald die von der RS90 erwarteten TXT-Records bekannt sind.

## Arbeitspakete

1. Ist-Logging/Protokollaufnahme:
   - RS90/App gegen echtes Home Assistant testen.
   - Alle Requests erfassen: Pfad, Methode, Body, Header, Reihenfolge.
   - Minimalantworten schrittweise kuerzen und pruefen, was die RS90 wirklich benoetigt.

2. Splitter bereinigen:
   - HA-Routing aus `sendHomeAssistantResponse()` extrahieren.
   - `sendHttpResponse()` und `sendErrorResponse()` gemeinsam weiterverwenden oder als Response-Helper kapseln.
   - WebSocket-Handshake separat halten, falls die RS90 ihn benoetigt.
   - `GetCompleteHAResponseComplete()` aus dem produktiven Pfad entfernen oder in eine Fixture-Datei verschieben.

3. Entity-Modell bauen:
   - Einheitliches internes Array/DTO fuer `domain`, `entity_id`, `name`, `state`, `attributes`, `target_type`, `target_id`, `capabilities`.
   - Bestehende Methoden `GetDataFromConfigurator("GetLights")`, `GetSwitches`, `GetAutomations`, `GetTemperatureSensors`, `GetBatterySensors`, `GetMotionSensors`, `GetIlluminanceSensors`, `GetMediaPlayers` als erste Datenquelle nutzen.
   - Fehlende Typen `remote` und ggf. Ablaufplan-Unterstuetzung ergaenzen.

4. Service-Executoren:
   - Light/Switch: `RequestAction($variableID, true/false)`.
   - Automation: `IPS_RunScript($scriptID)` oder Ablaufplan-Start sauber unterscheiden.
   - Sensor: keine Service-Aktionen, nur State.
   - Media Player: Mapping auf Profilwerte/Actions aus Konfigurator statt hart codierter Arrays.
   - Remote: erst nach realer RS90-Service-Namensaufnahme implementieren.

5. mDNS/Discovery:
   - Bestehende `RegisterMdnsService()`/`UnregisterMdnsService()` korrigieren.
   - Fuer HA-Emulation `_home-assistant._tcp` mit Port, Namen und minimalen TXT-Records testweise registrieren.
   - Parallel spaeter eigene Cantata/Symcon-Service-ID vorbereiten.

6. Tests:
   - Kleine Unit-/Skript-Tests fuer Serializer: Entity-Liste -> HA-State-JSON.
   - Manuelle Tests mit RS90 und Haptique Config App nach jedem Geraetetyp.
   - Debug-Logging so strukturieren, dass pro RS90-Request eindeutig Request und Response im Symcon-Debug sichtbar sind.

## Offene Fragen

- Welche exakten Requests sendet die RS90 bei Home Assistant Discovery und Pairing?
- Erwartet die RS90 wirklich `_home-assistant._tcp`, und welche TXT-Records muessen gesetzt sein?
- Nutzt die RS90 `GET /api/states` direkt fuer die Geraeteliste oder `POST /api/template` mit einer HA-Template-Abfrage?
- Welche `supported_features`-Werte prueft die RS90 fuer `light`, `media_player` und `remote`?
- Welche Services sendet die RS90 fuer `remote` genau?
- Wie sollen Symcon-Ablaufplaene eindeutig von Skripten unterschieden und gestartet werden?
- Soll `entity_id` fuer die RS90 stabil menschenlesbar sein oder reicht eine ID-basierte Entity-ID?

## Naechster sinnvoller Schritt

Zuerst die realen RS90-Requests gegen einen echten Home Assistant oder gegen den aktuellen Emulator mit maximalem Debug aufnehmen. Danach `GET /api/states` und die Service-Routen fuer `light`, `switch` und `automation` aus `GetCompleteHAResponse()` heraus in eine kleine HA-Emulationsklasse ueberfuehren. Erst wenn diese drei Typen stabil in der RS90 UI erscheinen und Aktionen in Symcon ausloesen, Media Player, Remote und Discovery erweitern.

## Status 2026-04-11

- Die Haptique Config App findet die Symcon-HA-Emulation ueber `_home-assistant._tcp`.
- `GET /api/states` liefert eine reduzierte Entity-Liste aus dem Import Configurator; die App zeigt damit Lights, Automations, Switch, Sensoren und Media Player an.
- REST-Service-Calls wie `POST /api/services/switch/turn_on` kommen an und fuehren `RequestAction()` in Symcon aus.
- Die RS90-UI aktualisiert den Live-Status noch nicht direkt. Die bisherigen Android-Logs vom echten Home Assistant zeigen, dass die RS90 dafuer HA-WebSocket-Events vom Typ `subscribe_trigger`/`event` erwartet.
- WebSocket-Handshake und `auth_required` werden gesendet, in den aktuellen Dumps kommt danach aber noch keine sichtbare `auth`- oder `subscribe_trigger`-Nachricht vom RS90-Client im Splitter an.
- Das fruehere Problem mit zwei aktiven Splitter-Instanzen ist erledigt. Die ueberfluessige zweite Splitter-Instanz wurde geloescht; weitere Media-Player-Tests koennen von genau einem aktiven Splitter ausgehen.
- Media-Player-Service-Ausfuehrung wurde im neuen `HomeAssistantServiceExecutor` ergaenzt. `media_play`, `media_pause` und `media_play_pause` setzen jetzt Werte auf die `ControlVariable`. `media_next_track` und `media_previous_track` setzen Werte auf die gemeinsame `NextPreviousVariable`. Die konkreten Integer-Werte werden bevorzugt aus dem Variablenprofil ueber Assoziationsnamen wie `Play`, `Pause`, `Next` und `Previous` ermittelt. `volume_set`, `volume_mute`, `shuffle_set` und `repeat_set` nutzen die entsprechenden im Import Configurator hinterlegten Zielvariablen.
- Light- und Media-Player-Listen im Import Configurator haben jetzt ein explizites `InstanceID`-Auswahlfeld. HA-Entity-IDs werden aus dieser Symcon-Instanz-ID gebaut, z.B. `media_player.57191` oder `light.12345`. Falls `InstanceID` leer ist, wird die Instanz als Fallback aus dem Parent der Hauptvariable (`ControlVariable` bzw. `SwitchVariable`) abgeleitet. Die bisherigen `Light_ID`/`Mediaplayer_ID` sind nur interne Listen-IDs und werden nicht mehr als HA-Import-ID verwendet.
- `RequestAction()`-Werte werden vor dem Senden anhand des Symcon-Variablentyps normalisiert. Damit wird z.B. `repeat_set` mit `"off"` fuer eine Boolean-Variable als `false` ausgefuehrt, waehrend Integer-Variablen weiterhin Profilwerte bzw. numerische Werte erhalten.
- `media_player.media_seek` wird jetzt unterstuetzt. Der von der RS90 gelieferte `seek_position`-Wert wird als absolute Position interpretiert, gegen `DurationVariable` bzw. `media_duration` gerechnet und dann als Prozentwert `0..100` auf die konfigurierte `PositionVariable` gesetzt.

## Naechster Debug-Fokus

1. Media-Player-Button auf der RS90 druecken und im Splitter-Debug die eingehende HA-Anfrage pruefen: Methode, Pfad, Body und `entity_id`.
2. Pruefen, ob die Anfrage als REST-Service `POST /api/services/media_player/...` oder als WebSocket-`call_service` kommt.
3. Pruefen, ob `RequestAction()` auf `ControlVariable`, `NextPreviousVariable`, `VolumeVariable` und `MuteVariable` korrekt ausgeloest wird.
4. Falls die Profil-Assoziationen der Integer-Variablen andere Namen oder Werte verwenden, die Zuordnung im Executor anpassen.

## Status 2026-04-12

- Media-Player-Import in der RS90/Config App nutzt jetzt die Symcon-Instanz-ID als HA-Entity-ID. Fuer den getesteten Dummy-Media-Player ist das `media_player.57191`.
- Die vorherige falsche Anzeige der Play/Pause-Variablen-ID als Media-Player-ID wurde behoben. `ControlVariable` bleibt internes Aktionsziel, wird aber nicht mehr als HA-Entity-ID verwendet.
- Play/Pause wurde mit der RS90 gegen die Dummy-Instanz erfolgreich getestet. Fuer den aktuellen Teststand gilt:
  - `media_play` setzt auf der `ControlVariable` den Wert `0`.
  - `media_pause` setzt auf der `ControlVariable` den Wert `1`.
  - Eine spaetere Ausbaustufe soll die Werte sauber ueber Variablenprofil oder Praesentation bestimmen.
- Next/Previous wurde mit der RS90 getestet:
  - `media_next_track` setzt auf der gemeinsamen `NextPreviousVariable` den Wert `1`.
  - `media_previous_track` setzt auf der gemeinsamen `NextPreviousVariable` den Wert `0`.
- `repeat_set` wurde mit der RS90 getestet. Die RS90 sendete z.B. `{"repeat":"off"}`. Der Executor normalisiert den Wert anhand des Symcon-Variablentyps; bei Boolean-Variablen wird `"off"` zu `false`.
- Die HA-State-Serialisierung fuer `repeat` wurde korrigiert. Boolean `false` wird als HA-kompatibles `"off"` ausgeliefert; dadurch zeigt die RS90 Repeat jetzt korrekt als aus an.
- `media_seek` wurde getestet und implementiert. Die RS90 sendet `seek_position` als absolute Position. Der Executor rechnet gegen `DurationVariable` bzw. `media_duration` und setzt die konfigurierte `PositionVariable` als Prozentwert `0..100`.
- Light-Import hat dieselbe ID-Grundsatzkorrektur erhalten: HA-Entity-ID soll die Symcon-Instanz-ID sein, nicht die Schaltvariablen-ID. Dafuer gibt es im Import Configurator jetzt ein explizites `InstanceID`-Feld; ohne gesetztes Feld wird die Instanz aus dem Parent der Hauptvariable abgeleitet.

## Pause / Naechster Wiedereinstieg

- Die Media-Player-Grundfunktionen sind mit Dummy-Variablen weit genug vorbereitet, um den naechsten Test gegen einen echten Media Player zu machen.
- Fuer belastbare weitere Tests wird ein echter Media Player in Symcon benoetigt. Aktuell existieren nur Dummy-Instanzen.
- Vor der Weiterarbeit an diesem Modul soll das separate Media-Player-Modul fertiggestellt bzw. ueberarbeitet werden, das einen echten Media Player bereitstellt oder Home Assistant aus Symcon ansteuert.
- Danach hier weiter testen:
  - Import eines echten Media Players in RS90/Config App.
  - Pruefen der echten Profil-/Praesentationswerte fuer Play, Pause, Next, Previous, Repeat, Shuffle, Volume und Seek.
  - Pruefen, ob Statusaenderungen des echten Media Players korrekt als HA-State und ggf. WebSocket-Event zur RS90 zuruecklaufen.
