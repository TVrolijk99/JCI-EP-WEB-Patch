<?php
/*
 * JCI-EP-WEB-Patch — gebruikershandleiding modal (HTML markup).
 * Copyright (C) 2026 Dymotica B.V. <info@dymotica.nl>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * Included from alarmdb.php / trend.php / graphic.php so the markup
 * lives in one place. The Help button in the topbar (`#helpBtn`)
 * triggers `#helpModal` via Bootstrap's data-toggle.
 *
 * Layout: near full-screen modal with a vertical table-of-contents
 * on the left and the manual body on the right. Both panes scroll
 * independently.
 */
?>
<div class="modal fade jci-help-modal" id="helpModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
        <h4 class="modal-title">
          <i class="mdi mdi-help-circle-outline"></i> Gebruikershandleiding
        </h4>
        <div class="jci-help-subtitle">Hoe je deze EasyIO Neo&#8209;web&#8209;UI gebruikt — inloggen, navigeren, alarmen, trends en beheer.</div>
      </div>

      <div class="modal-body">
        <!-- Verticale inhoudsopgave -->
        <nav class="jci-help-toc">
          <a href="#help-topbar"><i class="mdi mdi-page-layout-header"></i><span>Topbar</span></a>
          <a href="#help-feature"><i class="mdi mdi-view-list"></i><span>Feature&#8209;menu</span></a>
          <a href="#help-space"><i class="mdi mdi-file-tree"></i><span>Spaces</span></a>
          <a href="#help-prefixes"><i class="mdi mdi-tag-multiple-outline"></i><span>Prefix&#8209;tabel</span></a>
          <a href="#help-graphic-create"><i class="mdi mdi-pencil-plus"></i><span>Graphic aanmaken</span></a>
          <a href="#help-alarms"><i class="mdi mdi-bell-outline"></i><span>Alarmen</span></a>
          <a href="#help-trend"><i class="mdi mdi-chart-line"></i><span>Trend</span></a>
          <a href="#help-users"><i class="mdi mdi-account-cog-outline"></i><span>Gebruikersbeheer</span></a>
          <a href="#help-branding"><i class="mdi mdi-tag-edit-outline"></i><span>Topbar aanpassen</span></a>
          <a href="#help-utilities"><i class="mdi mdi-wrench-outline"></i><span>Utilities</span></a>
          <a href="#help-backup"><i class="mdi mdi-backup-restore"></i><span>Backup &amp; Restore</span></a>
        </nav>

        <!-- Hoofdinhoud -->
        <div class="jci-help-content">

          <section id="help-topbar">
            <h5><i class="mdi mdi-page-layout-header"></i> Topbar</h5>
            <p>De vaste balk bovenin het scherm bevat (van links naar rechts):</p>
            <ul>
              <li><strong>Hamburger</strong> <i class="mdi mdi-menu"></i> &mdash; klik om de zijbalk in&#8209; of uit te klappen. De pagina&#8209;inhoud verschuift mee.</li>
              <li><strong>Logo + titel</strong> &mdash; de naam van de installatie. Standaard staat hier &ldquo;Johnson Controls GBS&rdquo;. Admins kunnen dit aanpassen via het potlood&#8209;icoon (zie <a href="#help-branding">Topbar aanpassen</a>).</li>
              <li><strong>Help</strong> <i class="mdi mdi-help-circle-outline"></i> &mdash; opent deze handleiding.</li>
              <li><strong>Alarmbel</strong> <i class="mdi mdi-bell"></i> &mdash; rechtstreeks naar de alarmmanager. Zodra er actieve alarmen zijn, verschijnt linksboven op de bel een rood badge met het aantal.</li>
              <li><strong>Gebruikerspil</strong> &mdash; klikbare dropdown met:
                <ul>
                  <li><em>Change password</em> &mdash; eigen wachtwoord wijzigen.</li>
                  <li><em>Manage</em> &mdash; gebruikersbeheer (alleen voor admins / accounts met deze functie aan).</li>
                  <li><em>Signout</em> &mdash; uitloggen.</li>
                </ul>
              </li>
            </ul>
            <img class="jci-help-img" src="<?php A('../img/manual/topbar.png') ?>" alt="Topbar">
          </section>

          <section id="help-feature">
            <h5><i class="mdi mdi-view-list"></i> Feature&#8209;menu (zijbalk)</h5>
            <p>De zijbalk is verdeeld in twee secties: <strong>Feature</strong> bovenin (vaste functies) en <strong>Space</strong> daaronder (graphics&#8209;boom).</p>
            <p>Onder Feature staan, afhankelijk van je rechten:</p>
            <ul>
              <li><strong>Dashboard</strong> &mdash; alleen zichtbaar als de Dashboard&#8209;feature aan staat in jouw account.</li>
              <li><strong>Alarms</strong> <i class="mdi mdi-bell"></i> &mdash; alarmmanager.</li>
              <li><strong>Services</strong> <i class="mdi mdi-server-network"></i> &mdash; submenu met alle overige plugins die op de controller geactiveerd zijn (afhankelijk van installatie).</li>
              <li><strong>Trend</strong> <i class="mdi mdi-chart-line"></i> &mdash; submenu met trendtabellen die in <code>easyio.db</code> staan.</li>
              <li><strong>Utilities</strong> <i class="mdi mdi-wrench"></i> &mdash; backup, restore, herstart en andere systeem&#8209;acties (zie <a href="#help-utilities">Utilities</a>).</li>
            </ul>
            <img class="jci-help-img jci-help-img-narrow" src="<?php A('../img/manual/feature-menu.png') ?>" alt="Feature menu">
          </section>

          <section id="help-space">
            <h5><i class="mdi mdi-file-tree"></i> Spaces &mdash; de graphics&#8209;boom</h5>
            <p>Onder de <em>Space</em>&#8209;sectie van de zijbalk staat de boom met alle graphics van deze installatie. Hier navigeer je door het gebouw: van &eacute;&eacute;n centraal Home&#8209;overzicht naar verdiepingen, ruimtes en installaties.</p>
            <ul>
              <li><strong>Home</strong> <i class="mdi mdi-home-outline"></i> &mdash; bovenaan staat altijd een Home&#8209;snelkoppeling die je terugbrengt naar de hoofdpagina van de installatie.</li>
              <li><strong>Mappen</strong> &mdash; mapje&#8209;icoon met een pijltje rechts. Klik op het pijltje om uit&#8209; of in te klappen, klik op het label om de bijbehorende graphic te openen (indien aanwezig).</li>
              <li><strong>Graphics</strong> &mdash; klik op een regel om de graphic in het hoofdvenster te openen. Het icoon vertelt direct welk type pagina het is (zie <a href="#help-prefixes">Prefix&#8209;tabel</a>).</li>
              <li><strong>Samengevoegde rij</strong> &mdash; soms zie je &eacute;&eacute;n regel die zowel als groep als als graphic werkt: klikken op de tekst opent de graphic, klikken op het pijltje opent de onderliggende submap. Hierdoor hoef je niet eerst een map open te klappen voordat je bij het overzicht komt.</li>
            </ul>
            <p><strong>Wat kun je doen op een graphic?</strong></p>
            <ul>
              <li>Live waarden aflezen (temperaturen, standen, klepposities, energie&hellip;).</li>
              <li>Setpoints en schakelaars aanpassen (mits je daar rechten voor hebt). Klik op een waarde om de invoerdialoog te openen.</li>
              <li>Doorklikken naar onderliggende detail&#8209;graphics via knoppen of links die op de graphic zijn geplaatst.</li>
            </ul>
            <p>Geen toegang tot een bepaalde graphic? Een admin kan per gebruiker en per graphic <em>NoAccess</em> instellen via <em>Manage</em> &rarr; <em>Permissions</em>. Onbereikbare regels (en de mappen die alleen daaruit bestaan) verdwijnen dan automatisch uit jouw boom.</p>
            <img class="jci-help-img jci-help-img-narrow" src="<?php A('../img/manual/space-tree.png') ?>" alt="Spaces tree">
          </section>

          <section id="help-prefixes">
            <h5><i class="mdi mdi-tag-multiple-outline"></i> Prefix&#8209;tabel: welke 2&#8209;letter&#8209;code geeft welk icoon?</h5>
            <p>De boom kiest automatisch een passend icoon op basis van een 2&#8209;letter&#8209;prefix in de naam van een <em>grItem</em> of <em>grGroup</em>. De prefix wordt uit het zichtbare label gestript &mdash; <code>VW_LBK01</code> verschijnt dus simpelweg als &ldquo;LBK01&rdquo; in de boom, met een radiator&#8209;icoontje ervoor.</p>
            <table class="jci-help-prefix-table">
              <thead><tr><th>Prefix</th><th>Icoon</th><th>Categorie</th></tr></thead>
              <tbody>
                <tr><td><code>SC_</code></td><td><i class="mdi mdi-clock-outline"></i></td><td>Schedule (klokprogramma)</td></tr>
                <tr><td><code>ST_</code></td><td><i class="mdi mdi-cog-outline"></i></td><td>Settings</td></tr>
                <tr><td><code>MP_</code></td><td><i class="mdi mdi-floor-plan"></i></td><td>Map / floor plan</td></tr>
                <tr><td><code>BL_</code></td><td><i class="mdi mdi-office-building-outline"></i></td><td>Building / gebouw</td></tr>
                <tr><td><code>EN_</code></td><td><i class="mdi mdi-lightning-bolt"></i></td><td>Energy</td></tr>
                <tr><td><code>VW_</code></td><td><i class="mdi mdi-radiator"></i></td><td>Verwarming</td></tr>
                <tr><td><code>KL_</code></td><td><i class="mdi mdi-snowflake"></i></td><td>Koeling</td></tr>
                <tr><td><code>VT_</code></td><td><i class="mdi mdi-fan"></i></td><td>Ventilatie</td></tr>
                <tr><td><code>VL_</code></td><td><i class="mdi mdi-lightbulb-outline"></i></td><td>Verlichting</td></tr>
                <tr><td><code>EK_</code></td><td><i class="mdi mdi-server"></i></td><td>E&#8209;kast / regelkast</td></tr>
                <tr><td><code>VV_</code></td><td><i class="mdi mdi-developer-board"></i></td><td>VAV controller</td></tr>
                <tr><td><code>RR_</code></td><td><i class="mdi mdi-desk"></i></td><td>Ruimteregeling</td></tr>
              </tbody>
            </table>
            <p><strong>Geen prefix?</strong> Een graphic zonder prefix krijgt een kubus&#8209;icoon <i class="mdi mdi-cube-outline"></i> (equipment). Een groep zonder prefix krijgt een mapje&#8209;icoon <i class="mdi mdi-folder-outline"></i>.</p>
            <p class="jci-help-tip"><i class="mdi mdi-lightbulb-on-outline"></i> Tip: maak je een nieuwe categorie aan? Dan kan een ontwikkelaar de tabel uitbreiden in <code>js/menu_load.js</code> &mdash; één regel per nieuwe prefix.</p>
          </section>

          <section id="help-graphic-create">
            <h5><i class="mdi mdi-pencil-plus"></i> Een graphic aanmaken en in de boom plaatsen</h5>
            <p>Graphics worden aangemaakt en in de boom geplaatst via de <strong>EP&#8209;tool</strong> (de EasyIO Project&#8209;desktop&#8209;applicatie). De naam die je daar aan een graphic of map geeft, bepaalt direct hoe de regel in deze webinterface verschijnt &mdash; inclusief het icoon ervoor.</p>
            <ol>
              <li>Open de EP&#8209;tool, ga naar de tab <em>Graphics</em> en koppel met de controller.</li>
              <li>Maak een nieuwe graphic of map aan onder de gewenste plek in de boom.</li>
              <li>Geef de graphic een naam <strong>met de juiste 2&#8209;letter&#8209;prefix</strong> volgens de <a href="#help-prefixes">prefix&#8209;tabel</a>. De prefix wordt automatisch uit het zichtbare label gestript en bepaalt het icoon.<br>
                Voorbeelden: een luchtbehandelingskast wordt <code>VW_LBK01</code> (verschijnt als &ldquo;LBK01&rdquo; met radiator&#8209;icoon), een ruimteregeling <code>RR_Vergaderzaal</code>, een verdiepingsoverzicht <code>MP_Verdieping&nbsp;1</code>.
              </li>
              <li>Plaats de graphic in een logische map. Een gebouw met meerdere verdiepingen ziet er bijvoorbeeld zo uit:
                <pre class="jci-help-tree">
BL_Gebouw 1                  &larr; map met gebouw&#8209;icoon
├─ Verdieping 1              &larr; submap (geen prefix = mapje&#8209;icoon)
│  ├─ RR_Ruimte 1            &larr; ruimteregeling
│  └─ RR_Ruimte 2
└─ Verdieping 2
   └─ VV_Centrale VAV        &larr; VAV&#8209;controller</pre>
              </li>
              <li><strong>Truc &mdash; rij&#8209;samenvoeging</strong>: wil je dat een map zelf óók klikbaar is naar een overzichts&#8209;graphic, geef die graphic dan dezelfde label&#8209;naam als de map. <em>De prefix zet je dan op de graphic, niet op de map.</em> Voorbeeld:
                <pre class="jci-help-tree">
Koelmachines                 &larr; map (geen prefix)
├─ KL_Koelmachines           &larr; graphic met dezelfde label-naam (heeft de prefix)
└─ ST_Instellingen           &larr; sub&#8209;graphic met instellingen</pre>
                Resultaat: &eacute;&eacute;n regel &ldquo;Koelmachines&rdquo; met sneeuwvlok&#8209;icoon. Klikken op de tekst opent de overzichts&#8209;graphic, klikken op het pijltje opent de submap met instellingen.
              </li>
              <li><strong>Home aanwijzen</strong>: markeer in de EP&#8209;tool &eacute;&eacute;n graphic als startpagina &mdash; die verschijnt dan altijd bovenaan als &ldquo;Home&rdquo;.</li>
              <li>Sla op in de EP&#8209;tool en push de wijzigingen naar de controller. Hard&#8209;refresh (Ctrl+Shift+R) in de browser om de nieuwe boom te zien.</li>
            </ol>
            <p class="jci-help-tip"><i class="mdi mdi-information-outline"></i> Geen toegang tot de EP&#8209;tool? Vraag de installateur. De webinterface zelf is bewust <em>read&#8209;only</em> wat betreft de boomstructuur &mdash; je kunt hier wel navigeren en setpoints wijzigen, maar niet de boom bewerken.</p>
            <img class="jci-help-img" src="<?php A('../img/manual/ep-graphics.png') ?>" alt="EP-tool: graphics aanmaken met prefix in de naam">
          </section>

          <section id="help-alarms">
            <h5><i class="mdi mdi-bell-outline"></i> Alarmmanager</h5>
            <p>Open via <em>Alarms</em> in de zijbalk of door op de bel rechtsboven te klikken. Je ziet twee tabbladen:</p>
            <ul>
              <li><strong>Active</strong> &mdash; alle openstaande alarmen die nog niet zijn bevestigd. Dit tabblad opent standaard.</li>
              <li><strong>History</strong> &mdash; volledige tijdlijn, inclusief reeds bevestigde alarmen en &ldquo;teruggekeerd naar normaal&rdquo;&#8209;meldingen.</li>
            </ul>
            <p><strong>Statkaarten bovenin</strong> (alleen op Active): UNACKED ALARMS toont het totaal aantal openstaande alarmen, UNACKED SEVERITY breekt dat uit per ernst&#8209;categorie. Het balkje onderaan visualiseert de verhouding.</p>
            <p><strong>Severity (ernstniveaus)</strong>:</p>
            <ul>
              <li><span class="jci-help-pill jci-help-pill-critical">Critical</span> &mdash; prioriteit 1&nbsp;&ndash;&nbsp;39, vereist directe actie.</li>
              <li><span class="jci-help-pill jci-help-pill-high">High</span> &mdash; 40&nbsp;&ndash;&nbsp;79.</li>
              <li><span class="jci-help-pill jci-help-pill-medium">Medium</span> &mdash; 80&nbsp;&ndash;&nbsp;139.</li>
              <li><span class="jci-help-pill jci-help-pill-low">Low</span> &mdash; 140&nbsp;&ndash;&nbsp;249.</li>
              <li><span class="jci-help-pill jci-help-pill-normal">Normal</span> &mdash; prio 250 &mdash; alarm is teruggekeerd naar normaal, geen actie meer nodig.</li>
            </ul>
            <p><strong>Werken met alarmen</strong>:</p>
            <ul>
              <li>Klik <i class="mdi mdi-check"></i> op een rij om dat ene alarm te bevestigen, of vink meerdere rijen aan en klik <em>Ack</em> om in bulk te bevestigen.</li>
              <li>Klik op <i class="mdi mdi-comment-text-outline"></i> om notities aan een alarm toe te voegen of te lezen. Het cijfertje toont het aantal bestaande notities.</li>
              <li>Klik op <i class="mdi mdi-trash-can-outline"></i> in de History om een alarmrij te verwijderen (in bulk via aanvinken + de Delete&#8209;knop in de toolbar).</li>
              <li>Filteren via <i class="mdi mdi-filter-variant"></i>: datum&#8209;range, severity, status, tags, of zoeken in tekst/priority.</li>
              <li>Sorteren door op een kolomkop te klikken.</li>
              <li>Exporteren naar CSV of JSON via <em>Export</em>.</li>
              <li>Het lijstje wordt elke 30 seconden ververst, of klik <i class="mdi mdi-refresh"></i> voor handmatig.</li>
            </ul>
            <p>Mag je niet bevestigen of verwijderen? Dan zijn die knoppen verborgen &mdash; check met je admin via <em>Manage</em> &rarr; <em>Accounts</em> &rarr; <em>Patch permissions</em>.</p>
            <img class="jci-help-img jci-help-img-crop" src="<?php A('../img/manual/alarms-critical.png') ?>" alt="Alarmmanager met 1 critical alarm">
          </section>

          <section id="help-trend">
            <h5><i class="mdi mdi-chart-line"></i> Trendpagina</h5>
            <p>Onder Feature &rarr; Trend in de zijbalk staat een lijst van alle beschikbare trendtabellen (de tabellen in <code>easyio.db</code>). Klik op een tabelnaam om de bijbehorende grafiek te openen.</p>
            <p><strong>Punten kiezen (linker paneel)</strong></p>
            <ul>
              <li>Elke meetwaarde uit de tabel staat als regel met een gekleurde stip en een vinkje. Vink aan/uit om de lijn op de grafiek te tonen of verbergen.</li>
              <li>Achter de naam staat een type&#8209;label: <em>ANALOG</em> (continue waarden) of <em>BINARY</em> (aan/uit).</li>
              <li>De keuze wordt server&#8209;side onthouden &mdash; iedereen die deze tabel opent ziet dezelfde standaardselectie.</li>
              <li>Klik <i class="mdi mdi-chevron-double-left"></i> om het paneel in te klappen voor een breder diagram. <i class="mdi mdi-chevron-double-right"></i> klapt het weer uit.</li>
            </ul>
            <p><strong>Tijdsperiode kiezen (rechtsboven)</strong></p>
            <ul>
              <li><em>Quick range</em> &mdash; vooraf gedefinieerde periodes: laatste uur, 6 uur, dag, week, 30 dagen, vandaag, gisteren, deze week, deze maand. De &ldquo;Last&hellip;&rdquo;&#8209;opties zijn <em>rolling</em>: ze schuiven mee terwijl de chart elke 60 seconden ververst.</li>
              <li>Of: kies eigen <em>From</em> en <em>To</em>&#8209;datums via de kalender&#8209;icoontjes.</li>
            </ul>
            <p><strong>Werken met de grafiek</strong></p>
            <ul>
              <li>Slepen op de chart = inzoomen op een tijdsbereik.</li>
              <li><i class="mdi mdi-cursor-move"></i> = pan&#8209;modus aan/uit (slepen verschuift in plaats van inzoomen).</li>
              <li><i class="mdi mdi-magnify-plus-outline"></i>/<i class="mdi mdi-magnify-minus-outline"></i> = stapsgewijs in&#8209;/uitzoomen rondom het midden.</li>
              <li><i class="mdi mdi-restore"></i> = zoom resetten naar de gekozen tijdsperiode.</li>
              <li><i class="mdi mdi-download"></i> Export &rarr; PNG, SVG of CSV (CSV bevat de eenheid achter de kolomnaam, bv. <code>Buitenluchttemp (&deg;C)</code>).</li>
            </ul>
            <img class="jci-help-img" src="<?php A('../img/manual/trend.png') ?>" alt="Trend pagina">

            <h6 style="margin-top:18px;">Eenheden en statussen aanpassen <span class="jci-help-admin-badge">trend&#8209;edit</span></h6>
            <p>Met het <i class="mdi mdi-pencil-outline"></i>&#8209;icoon bovenaan het puntenpaneel ga je in <em>edit&#8209;mode</em>:</p>
            <ul>
              <li>Vul per analoog punt een eenheid in (<code>&deg;C</code>, <code>%</code>, <code>kWh</code>, <code>m&sup3;/h</code>&hellip;). Deze verschijnt in de tooltip op de grafiek en in de CSV&#8209;export.</li>
              <li>Voor binaire punten: vul de teksten in voor 0 en 1, bijvoorbeeld <code>UIT</code>/<code>AAN</code>, <code>Inactief</code>/<code>Actief</code>, of <code>Gesloten</code>/<code>Open</code>.</li>
              <li>Klik <i class="mdi mdi-content-save-outline"></i> Save om alles op te slaan, of Cancel om af te breken.</li>
              <li>Aangepaste teksten/eenheden zijn voor iedereen zichtbaar &mdash; ze worden niet per gebruiker opgeslagen.</li>
            </ul>
            <p>De potlood&#8209;knop is alleen zichtbaar als je het recht <em>trend&#8209;edit</em> hebt. Vraag een admin om dit aan te zetten via <em>Manage</em> &rarr; <em>Accounts</em>.</p>
            <img class="jci-help-img jci-help-img-narrow" src="<?php A('../img/manual/trend-edit.png') ?>" alt="Trend punten bewerken">
          </section>

          <section id="help-users">
            <h5><i class="mdi mdi-account-cog-outline"></i> Gebruikersbeheer <span class="jci-help-admin-badge">admin</span></h5>
            <p>Open via gebruikerspil rechtsboven &rarr; <em>Manage&hellip;</em>. Naast de standaard&#8209;tabbladen die EasyIO biedt zijn er twee onderdelen die specifiek met deze patch te maken hebben:</p>
            <ul>
              <li><strong>Graphics&#8209;permissies</strong> (tab <em>Permissions</em>) &mdash; per gebruiker (linker rail) een tabel van alle graphics. Per regel kies je <em>NoAccess</em>, <em>ReadOnly</em> of <em>ReadWrite</em>. Een NoAccess&#8209;regel verdwijnt automatisch uit de Spaces&#8209;boom van die gebruiker, en bovenliggende mappen die daardoor leeg blijven verdwijnen ook.</li>
              <li><strong>Patch&#8209;permissies</strong> (tab <em>Accounts</em>, onderaan het detailpaneel) &mdash; drie extra vinkjes die door deze patch zijn toegevoegd. Ze worden automatisch opgeslagen zodra je ze aan&#8209; of uitvinkt:
                <ul>
                  <li><em>Allow alarm acknowledge</em> &mdash; bevestig&#8209;knoppen verschijnen in de alarmmanager.</li>
                  <li><em>Allow alarm delete</em> &mdash; verwijder&#8209;knoppen verschijnen in de history.</li>
                  <li><em>Allow trend units / state&#8209;text edit</em> &mdash; potlood&#8209;icoon op de trendpagina is bruikbaar.</li>
                </ul>
                Deze rechten worden ook server&#8209;side gehandhaafd: het bypassen via developer&#8209;tools werkt niet.
              </li>
            </ul>
            <p class="jci-help-tip"><i class="mdi mdi-lightbulb-on-outline"></i> Goede praktijk: maak rolspecifieke accounts (Engineer/Manager/Operator/Viewer) met afnemende rechten in plaats van iedereen op admin te zetten.</p>
            <img class="jci-help-img" src="<?php A('../img/manual/manage-perms.png') ?>" alt="Manage permissions met patch permissions">
          </section>

          <section id="help-branding">
            <h5><i class="mdi mdi-tag-edit-outline"></i> Topbar aanpassen <span class="jci-help-admin-badge">admin</span></h5>
            <p>Klik op het potlood naast de titel in de topbar:</p>
            <ul>
              <li><strong>Titel</strong> &mdash; vrije tekst, max 64 tekens. Wordt direct doorgevoerd op alle pagina&rsquo;s zonder herladen.</li>
              <li><strong>Logo</strong> &mdash; upload PNG, JPG, SVG, GIF of WEBP, max 1&nbsp;MB. Het logo wordt automatisch geschaald op de hoogte van de balk en links van de titel geplaatst.</li>
              <li><strong>Logo verwijderen</strong> &mdash; valt terug op het standaard&#8209;logo dat met de patch meekomt (<code>img/top&#8209;bar&#8209;logo.png</code>).</li>
            </ul>
            <p>Branding wordt opgeslagen in <code>app/grdata/branding.json</code> + <code>app/grdata/branding_logo.&lt;ext&gt;</code> en gaat <strong>mee in de backup</strong>. Na een restore op een andere controller komt jouw eigen huisstijl dus weer terug.</p>
            <img class="jci-help-img jci-help-img-narrow" src="<?php A('../img/manual/branding.png') ?>" alt="Topbar aanpassen modal">
          </section>

          <section id="help-utilities">
            <h5><i class="mdi mdi-wrench-outline"></i> Utilities</h5>
            <p>Onder Feature &rarr; Utilities staan systeem&#8209;acties die je nodig hebt om de controller te onderhouden. Welke items zichtbaar zijn hangt af van je accountrechten:</p>
            <ul>
              <li><strong>Backup&hellip;</strong> &mdash; complete configuratie&#8209;backup downloaden (zie <a href="#help-backup">Backup &amp; Restore</a>).</li>
              <li><strong>Restore&hellip;</strong> &mdash; backupbestand uploaden en terugzetten.</li>
              <li><strong>Restart&hellip;</strong> &mdash; webserver/sedona&#8209;runtime herstarten (geen impact op netwerk/OS).</li>
              <li><strong>Reboot&hellip;</strong> &mdash; volledige reboot van de controller. Reken op enkele minuten downtime.</li>
              <li><strong>Upgrade Firmware&hellip;</strong> &mdash; firmware&#8209;image uploaden en flashen. <em>Voorzichtig: niet onderbreken.</em></li>
              <li><strong>Config Ports&hellip;</strong> &mdash; service&#8209;poorten van de controller wijzigen.</li>
              <li><strong>Change DateTime&hellip;</strong> &mdash; datum/tijd aanpassen, NTP toggelen.</li>
              <li><strong>Change OS Account Password&hellip;</strong> &mdash; wachtwoord van de Linux&#8209;OS&#8209;laag (niet de webgebruiker).</li>
              <li><strong>DB Manager&hellip;</strong> &mdash; opent phpliteadmin voor directe SQLite&#8209;toegang. Alleen voor gevorderden.</li>
            </ul>
          </section>

          <section id="help-backup">
            <h5><i class="mdi mdi-backup-restore"></i> Backup &amp; Restore</h5>
            <p>Een backup pakt alle relevante configuratie samen in &eacute;&eacute;n <code>.tgz</code>&#8209;bestand:</p>
            <ul>
              <li>Graphics + grNav.xml.</li>
              <li>Gebruikersaccounts, rechten, sessies (uit <code>cpt&#8209;web.db</code>).</li>
              <li>Live data en historie.</li>
              <li><strong>Patch&#8209;configuratie</strong>: alles onder <code>app/grdata/</code>, dus inclusief je trend&#8209;eenheden, patch&#8209;permissies en topbar&#8209;branding.</li>
              <li>Plugins die niet built&#8209;in zijn (bv. AlarmDB).</li>
            </ul>
            <p><strong>Werkwijze</strong>:</p>
            <ol>
              <li>Utilities &rarr; <em>Backup&hellip;</em> &rarr; kies bestemming (Flash of SDCard) &rarr; download het tarball.</li>
              <li>Bewaar de backup veilig, gemerkt met datum + controllernaam.</li>
              <li>Voor herstel: Utilities &rarr; <em>Restore&hellip;</em> &rarr; sleep het tarball in het uploadveld &rarr; bevestigen.</li>
              <li>De controller herstart automatisch na een restore. Wacht tot de loginpagina weer beschikbaar is.</li>
            </ol>
            <p class="jci-help-tip"><i class="mdi mdi-lightbulb-on-outline"></i> Maak een backup direct n&aacute; het uitleveren en daarna na elke significante wijziging. Bewaar er minimaal twee versies (gisteren + week&#8209;oud) voor noodgevallen.</p>
          </section>

        </div><!-- /.jci-help-content -->
      </div><!-- /.modal-body -->

      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Sluiten</button>
      </div>
    </div>
  </div>
</div>

<script>
/*
 * Scroll-spy voor de help-modal: zet `.active` op de ToC-link die hoort bij
 * de sectie die bovenaan in beeld staat. Werkt op zowel scroll-events als
 * op klik (klik markeert direct, scroll volgt vanzelf via smooth-scroll).
 */
(function () {
	if (typeof jQuery === 'undefined') return;
	jQuery(function ($) {
		var $modal   = $('#helpModal');
		if (!$modal.length) return;
		var $content = $modal.find('.jci-help-content');
		var $toc     = $modal.find('.jci-help-toc');
		if (!$content.length || !$toc.length) return;

		function setActive(id) {
			$toc.find('a').removeClass('active');
			if (!id) return;
			$toc.find('a[href="#' + id + '"]').addClass('active');
		}

		function currentSectionId() {
			var sections = $content.find('section').toArray();
			if (!sections.length) return null;
			var contentTop = $content.offset().top;
			// Sectie is "actief" zodra de top 80px onder de content-rand komt.
			var trigger = contentTop + 80;
			var current = sections[0].id;
			for (var i = 0; i < sections.length; i++) {
				var rect = sections[i].getBoundingClientRect();
				if (rect.top <= trigger) current = sections[i].id;
				else break;
			}
			return current;
		}

		function refresh() { setActive(currentSectionId()); }

		// Throttle scroll via rAF.
		var ticking = false;
		$content.on('scroll', function () {
			if (ticking) return;
			ticking = true;
			window.requestAnimationFrame(function () {
				refresh();
				ticking = false;
			});
		});

		// Klik op ToC: direct markeren (scroll-behavior: smooth doet de rest).
		$toc.on('click', 'a', function () {
			var id = ($(this).attr('href') || '').replace(/^#/, '');
			if (id) setActive(id);
		});

		// Reset bij elke open van de modal.
		$modal.on('shown.bs.modal', function () {
			$content.scrollTop(0);
			refresh();
		});
	});
})();
</script>
