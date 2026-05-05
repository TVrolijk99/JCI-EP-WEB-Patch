# JCI EP Web Patch

Een visuele en functionele patch voor de webinterface van de **Johnson
Controls EasyIO Neo EC-controllers** (de graphic webservice op poort 80
van de controller). De patch geeft de standaard EP-webinterface een
moderne look-and-feel in de stijl van de Johnson Controls EasyIO Neo
System Supervisor en voegt een aantal eigen features toe — zonder de
native graphic-editor en plugin-flow van JCI te breken.

## Inhoud

- [Wat zit er in deze patch](#wat-zit-er-in-deze-patch)
- [Compatibiliteit](#compatibiliteit)
- [Installatie](#installatie)
- [Gebruik] (#gebruik)
- [Updaten](#updaten)
- [Backup &amp; restore](#backup--restore)
- [Disclaimer](#disclaimer)
- [Licentie](#licentie)
- [Contact](#contact)

## Wat zit er in deze patch

**Visueel**

- Volledig herstijlde topbar (53px, Noto Sans, JCI gradient line) met
  hamburger, logo + titel, help-knop, alarmbel met badge en
  gebruikers-dropdown.
- Linker zijbalk in JCI Neo-stijl, met inklapbare sub-menus en een
  SPACE-tree die op basis van naam-prefixes automatisch een passend
  icoon (Material Design Icons) toekent — `VW_` = radiator,
  `KL_` = sneeuwvlok, `RR_` = bureau, `EN_` = bliksem, etc.
- Lichte theme met JCI-blauw als primaire accentkleur, consistente
  severity-tokens (Critical / High / Medium / Low / Normal) op alarm-
  en trend-pagina's.
- Login-pagina herontworpen met split-pane layout en JCI-branding.

**Functioneel**

- **Native alarmmanager** — vervangt de legacy AlarmDB-pluginpagina.
  Tabbladen Active / History, severity-filters, status-berekening
  (alarm-actief vs. teruggekeerd-naar-normaal), bulk-acknowledge,
  bulk-delete, notities, CSV/JSON export, 30s auto-refresh.
- **Native trendpagina** — ApexCharts-based grafiek voor elke trendtabel
  uit `easyio.db`. Per-punt unit + state-text persistentie, rolling
  presets (laatste uur / dag / week / maand), brush-zoom + pan, PNG /
  SVG / CSV export (CSV inclusief unit per kolom).
- **Patch-permissies** — drie extra account-flags
  (`alarm_ack` / `alarm_delete` / `trend_edit`), aan/uit per gebruiker
  via *Manage* &rarr; *Accounts*. Server-side gehandhaafd.
- **Topbar branding** — admins kunnen de installatie-titel en het
  logo wijzigen via een potlood-knop in de topbar.
- **Gebruikershandleiding** — geïntegreerde hulp-modal (`?`-knop in
  de topbar) met Nederlandse uitleg en screenshots.
- **Graphic-page chrome** — automatisch passende zoom (fit-to-viewport),
  manueel zoomen met +/- of muiswiel, breadcrumb boven de graphic.
- **NoAccess-filter** — graphics waarop de gebruiker geen rechten heeft
  worden automatisch uit de SPACE-boom gefilterd, op basis van de
  bestaande JCI `cpt-web.db` permissions-tabel.

## Compatibiliteit

- **Hardware**: Johnson Controls / EasyIO Neo EC-serie controllers
  (FG/FS/FW). Getest op Dymotica's referentie-controller met EC firmware
  die de `graphic.php`-webservice in `/var/www/public/sdcard/server/grweb/`
  draait.
- **Browser**: moderne Chromium-based browsers (Chrome, Edge, Brave).
  Firefox werkt grotendeels; CSS `zoom` op de graphic-pagina is een
  Chromium-feature en geeft daar het beste resultaat.

## Installatie

1. Pak de inhoud van `EP/` uit en overlay het op de bestaande EP-folder
   van de controller

2. "Full deploy" in de "EP tool"

3. Hard-refresh in de browser (`Ctrl`+`Shift`+`R`) om de oude assets
   uit de cache te verdrijven.

4. Log in op de webinterface zoals voorheen. Het standaard `admin`
   account werkt ongewijzigd.

## Gebruik

Zie na full deploy de "Help" knop rechts boven voor het juiste gebruik van de alarm manager, trend widgets en spaces three.

## Updaten

Een nieuwe patch-versie is altijd een **complete drop-in van de
`EP/`-tree**. Geen migraties, geen scripts. 

## Backup &amp; restore

Gebruik de bestaande JCI Utilities *Backup* / *Restore*. Restore op
een andere controller herstelt zowel graphics als alle
patch-configuratie.

## Disclaimer

Deze patch is **geen officieel Johnson Controls / EasyIO product**.
Het is een door Dymotica B.V. ontwikkelde overlay die zichzelf in de
bestaande webservice nestelt. Native firmware-updates van de controller
kunnen onderdelen van de patch overschrijven; het is dan een kwestie
van opnieuw deployen.

De software wordt geleverd "as-is", zonder enige garantie. Zie het
[`LICENSE`](LICENSE) bestand voor de volledige juridische tekst.

## Licentie

Distributed onder de **GNU General Public License v3.0 of later**
(`SPDX-License-Identifier: GPL-3.0-or-later`). De volledige
licentietekst staat in [`LICENSE`](LICENSE).

Niet onder GPL vallen:

- de meeverpakte vendor-libraries (ApexCharts, bootstrap-datetimepicker,
  jquery.nanoscroller, Material Design Icons font + CSS);
- de `plugins/alarmdb/` plugin &mdash; origineel MIT-licensed door
  Andrius Jasiulionis (2017), zie `EP/grweb/public/plugins/alarmdb/licence`.

## Contact

Auteur en onderhoud: **Dymotica B.V.**
&middot; e-mail: <info@dymotica.nl>

Issues, pull requests en feature-suggesties zijn welkom.
