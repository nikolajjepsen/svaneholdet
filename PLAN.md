# Svaneholdets vagtplan

Første version skal gøre det muligt for et Smukfest-hold med over 70 frivillige at gå fra invitation til bemandet vagtplan og opgjorte timer. Svaneholdet bliver den første organisation. Datamodellen skal fra starten kunne rumme flere hold med hver deres medlemmer, roller og vagtplaner, men brugerfladen til flere hold venter til efter piloten.

## Teknologi

Én samlet Laravel-app uden et API mellem backend og frontend. Laravel styrer routing, rettigheder og data, og siderne renderes på serveren.

| Del | Valg |
| --- | --- |
| Framework | Laravel med det officielle Livewire starter kit |
| Brugerflade | Livewire med komponenter i én fil, så logik og markup ligger samme sted. Alpine til små ting i browseren |
| Komponenter | Flux til formularer, modaler, tabeller og knapper, så siderne ser færdige ud uden eget designarbejde |
| Database | PostgreSQL |
| Rettigheder | Laravel Policies ved alle læsninger og ændringer, også i Livewire-handlinger |
| Kvalitet | Pest til tests, Larastan til statisk analyse og Pint til formatering |
| AI-agenter | Laravel Boost, så agenter kender projektets Laravel-version og konventioner |

[Laravel Starter Kits](https://laravel.com/framework/docs/13.x/starter-kits) · [Laravel Authorization](https://laravel.com/framework/docs/13.x/authorization) · [PostgreSQL Constraints](https://www.postgresql.org/docs/current/ddl-constraints.html)

## Første version

### Organisationer, roller og login

- Et Smukfest-hold er en organisation. En bruger kan være medlem af flere organisationer og have en anden rolle på hvert hold. Datamodellen og alle policies tager højde for det fra start. Siden til at skifte mellem organisationer og opsætning af nye hold bygges først, når der er brug for et hold nummer to.
- Start med rollerne **frivillig** og **administrator**. Administratoren kan også tage vagter. Begge roller bruger den samme tilmelding, og administratoren kan godkende og tildele pladser som en særskilt handling.
- **Login sker med et link på mail**, ikke med adgangskode. Brugeren skriver sin e-mail og får et tidsbegrænset engangslink. På telefonen forbliver brugeren logget ind længe, så de frivillige sjældent skal logge ind igen under festivalen.
- Administratoren opretter en invitation med navn, e-mail og rolle. Linket i invitationsmailen aktiverer kontoen og logger personen ind i samme klik. Har personen allerede en konto, tilføjes medlemskabet til den konto. Administratoren kan gensende invitationen, så det gamle link udløber.
- Opret Svaneholdet, det første festivalår og den første administrator som led i opsætningen.

### Festivalår

- Alle vagter hører til et festivalår, fx "Smukfest 2027". Vagtplan, dagsoversigt og timeoversigt viser det aktuelle år som standard.
- Det kræver én tabel nu og sparer en migrering af alle vagter næste år. Kopiering af en hel vagtplan til et nyt år bygges senere, men datamodellen er klar til det.

### Vagter og udrulning

- En vagt har en titel, start- og sluttidspunkt med dato, pladsantal og en indstilling for, om tilmelding kræver godkendelse. Der kan være flere vagter samme dag, også på samme tid. Start- og slutdato gør det muligt at planlægge en vagt over midnat. Vis tider i `Europe/Copenhagen` og gem entydige tidspunkter i databasen.
- **Administratoren opretter vagter ved at rulle et mønster ud over en periode.** Det er den normale måde at oprette vagter på. Enkeltvagter kan stadig oprettes og rettes bagefter.

Udrulningen spørger om:

| Felt | Eksempel |
| --- | --- |
| Titel | Bar |
| Periode | 3. til 10. august |
| Mønster | 2 × 6 timer, 1 × 12 timer eller eget antal og længde |
| Første vagt starter | 08.00 |
| Pladser pr. vagt | 6 |
| Kræver godkendelse | Nej |

- Med 2 × 6 timer fra 08.00 får hver dag en vagt 08.00–14.00 og en 14.00–20.00. Med 1 × 12 timer fra 20.00 går vagten til 08.00 næste morgen.
- Før vagterne gemmes, viser en forhåndsvisning alle vagter dag for dag. Administratoren kan fravælge enkelte dage, fx opstilling eller den sidste dag. Hvis en ny vagt har samme titel og overlapper en eksisterende, vises en advarsel.
- Alle vagter fra én udrulning gemmes i samme transaktion og hører til en **vagtserie**. Hver vagt er bagefter en almindelig vagt, som kan rettes for sig. Fra serien kan administratoren ændre pladsantal eller godkendelseskrav på alle kommende vagter i serien på én gang. En serie kan slettes, så længe ingen af dens vagter har tilmeldinger.

### Tilmelding og afmelding

- På en vagt med åben tilmelding får medlemmet straks en bekræftet plads, hvis der er ledig kapacitet. På en vagt med godkendelse opretter tilmeldingen en afventende anmodning, som en administrator kan godkende eller afslå.
- Afventende anmodninger vises særskilt og optager først en plads ved godkendelse. Direkte tilmelding, godkendelse og manuel tildeling kontrollerer pladsantal og gemmer ændringen i samme databasetransaktion med lås på vagten. Så kan to samtidige handlinger ikke begge tage den sidste plads. Et medlem kan kun have én aktiv tilmelding til samme vagt. [Laravel Pessimistic Locking](https://laravel.com/framework/docs/13.x/queries#pessimistic-locking)
- **Frivillige kan selv melde fra indtil 48 timer før vagten.** Derefter skal de kontakte en administrator, som kan melde dem fra. Fristen er en indstilling på organisationen.
- **Vagtplanen** viser vagter dag for dag med tid, ledige pladser og tydelige handlinger for tilmelding. **Mine vagter** viser kommende og tidligere vagter med status og timer.

### Bemanding og timer

- **Dagsoversigten** viser hver vagts bekræftede pladser ud af kapaciteten, afventende anmodninger og samlet antal unikke bekræftede personer på dagen. En person med to vagter tæller én gang i dagstotalen og én gang på hver af sine vagter. Vagter over midnat vises på begge dage, hvor de har bemanding. Siden opdaterer sig selv hvert 10. sekund med `wire:poll`, så administratoren ser nye tilmeldinger uden at genindlæse.
- Administratoren kan åbne en vagt og rette faktisk møde- og sluttid direkte på deltagerlisten. Vagtens planlagte tider bruges som udgangspunkt ved bekræftelse af fremmødet. Hvis vagten var planlagt til 12.00–16.00, og en frivillig mødte 12.30 og gik 15.45, registreres 3 timer og 15 minutter. Registrér hvem der ændrede tiderne og hvornår.
- Timeoversigten viser planlagte timer og registrerede arbejdstimer pr. person. Arbejdstimer tæller først, når fremmødet er bekræftet, og udeblivelser registreres med 0 timer. Historikken viser vagtens dato, planlagte tider og de faktisk registrerede tider.

## Datamodel

| Entitet | Indhold |
| --- | --- |
| Bruger | Navn og e-mail |
| Login-link | Bruger, engangstoken, udløb og brug |
| Organisation | Holdets navn, tidszone og frist for afmelding |
| Festivalår | Organisation, navn, første og sidste dag |
| Medlemskab | Bruger, organisation, rolle og adgangsstatus |
| Invitation | Organisation, modtagers navn og e-mail, rolle, udløb og indløsning |
| Vagtserie | Festivalår, titel, periode og mønster, som serien blev rullet ud med |
| Vagt | Festivalår, eventuel vagtserie, titel, planlagt start og slut, kapacitet og godkendelseskrav |
| Tilmelding | Vagt, medlemskab, status, fremmøde og faktiske tider |

Tilmeldingen forbinder en vagt med et medlemskab i samme organisation. Det gør det muligt at beholde historikken, når et medlems adgang fjernes. Vagtplanen og timeoversigten kan filtreres på en periode inden for festivalåret.

## Byggerækkefølge

1. **Adgang og invitation.** Opsæt Laravel med Livewire, Flux, Pest, Larastan og Pint. Byg organisationer, medlemskaber, roller, login via mail-link og invitation. Gennemfør en rigtig invitation i et testmiljø fra oprettelse til første login. Prøv med to organisationer i testene, så rolle og adgang kontrolleres på tværs fra starten.
2. **Festivalår, udrulning og selvbetjening.** Byg festivalåret, udrulningen med forhåndsvisning, vagtserier, den mobilvenlige dagsliste og Mine vagter. Gør direkte tilmelding, afventende anmodninger, godkendelse og afmelding til gennemgående forløb. Afprøv udrulning med 2 × 6 og 1 × 12 timer, en vagt over midnat, flere vagter samme dag, admin på en vagt, afmelding efter fristen og to samtidige forsøg på sidste plads.
3. **Dagsoversigt og timeregistrering.** Byg dagstælling, deltagerlisten med redigering af tider og historik pr. frivillig. Kontrollér, at to vagter samme dag tæller én person, og at en forkortet vagt giver det rigtige timetal. Afprøv også vagter over midnat og udeblivelser.
4. **Pilot med Svaneholdet.** Sæt mailafsendelse, HTTPS, backup og gendannelse op. Lad et par administratorer og frivillige gennemføre forløbet på telefon fra invitation til timeregistrering. Brug deres feedback til at justere navigationen, før resten af holdet inviteres.

## Beslutninger til næste runde

| Emne | Forslag eller afklaring |
| --- | --- |
| Overlap | Skal overlappende vagter for samme person blokeres eller udløse en advarsel, også hvis de er i forskellige organisationer? |
| Godkendelse | Skal en administrator kunne sætte sig selv direkte på en vagt, der ellers kræver godkendelse? |
| Ændringer efter tilmelding | Skal den frivillige have besked, hvis tidspunktet ændres, vagten aflyses, eller en anmodning godkendes? Hvordan håndteres en ny kapacitet under det allerede bekræftede antal, også når den ændres for en hel vagtserie? |
| Timer | Trækkes pauser fra, især på 12-timersvagter? Skal timerne bruges til et minimumskrav eller eksport? |
| Nye hold og kopiering | Skal flere hold oprettes centralt? Skal næste års vagtplan kopieres fra sidste år eller rulles ud på ny? |
