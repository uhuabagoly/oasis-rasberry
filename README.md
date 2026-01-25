# oasis-rasberry
# Oasis — automata növényfelügyeleti és öntözőrendszer (README / Kézikönyv)

> **Kézikönyv jellegű README** — minden leírt rész közvetlenül köthető a projekthez. A tartalom egyben, változtatás nélkül felhasználható GitHub-on.

---

## Tartalomjegyzék
- Rövid összefoglaló
- Főbb képességek
- BOM (alkatrészlista)
- Előkészületek / szükséges eszközök
- FONTOS FIGYELMEZTETÉSEK
- GPIO kiosztás (pinout)
- Bekötési útmutató — minden modulra kiterjedően
- Tápellátás és WAGO csoportosítás
- Fizikai rögzítés / szerelési tanácsok
- Adatnaplózás és fájlrotáció
- Telepítés és függőségek
- Indítás / systemd szolgáltatás példa
- Troubleshooting — tipikus hibák és megoldások
- 3D nyomtatható ház — rövid logika
- Záró gondolatok

---

## Rövid összefoglaló
Az **Oasis** egy Raspberry Pi Zero 2 W alapú, szenzorvezérelt automata növényfelügyeleti és öntözőrendszer. A rendszer talajnedvességet, fényt, CO₂-szintet, hőmérsékletet, páratartalmat, légnyomást és vízszintet mér, majd PWM-vezérléssel irányítja a víz- és légszivattyúkat. A működés hibatűrő, a mérések folyamatosan naplózásra kerülnek.

---

## Főbb képességek
- 3× SOILCAP-V20 talajnedvesség-szenzor (átlagolt mérés)
- Automatikus öntözés PWM-mel vezérelt vízszivattyúval
- Időzített légszivattyú működtetés
- Fényerő mérés 2× BH1750 szenzorral
- Hőmérséklet / páratartalom / légnyomás mérés (BME280)
- CO₂ szint figyelése (SCD40-M)
- Vízszint mérés (WLD-75)
- Folyamatos adatnaplózás és automatikus log rotáció

---

## BOM (alkatrészlista)
- Raspberry Pi Zero 2 W
- ADS1115 (I²C ADC, cím: 0x48)
- ADC-16CH (CD74HC4067 multiplexer)
- 3× SOILCAP-V20 talajnedvesség szenzor
- 2× GY-302 (BH1750) fényérzékelő (I²C: 0x23 és 0x5C)
- BME280-M (I²C: 0x76)
- SCD40-M CO₂ szenzor (I²C: 0x62)
- WLD-75 vízszint-érzékelő
- PWMMOS-4 PWM MOSFET modul
- Vízszivattyú (DC)
- Légszivattyú (DC)
- Külön 5V tápegység a motoroknak
- MICROUSB-DIP5
- AWG28-10C kábel
- WAGO sorkapcsok
- 3× 4×16 mm csavar (hardware rögzítés)

---

## Előkészületek / szükséges eszközök
- Jó minőségű forrasztópáka és ón
- Zsugorcső több méretben
- Multiméter (feszültség és ellenállás mérés)
- Csipesz, oldalcsípő
- Csavarhúzó készlet
- Kábelrendezők

---

## FONTOS FIGYELMEZTETÉSEK
**SOHA ne vezess 5V-ot közvetlenül a Raspberry Pi GPIO lábaira!**
A GPIO-k kizárólag 3.3V-os jeleket fogadnak. 5V bemenet esetén a Raspberry Pi véglegesen károsodik.

**KÖZÖS GND KÖTELEZŐ.**
A Raspberry Pi, az összes szenzor, az ADS1115, a multiplexer, a PWMMOS-4 modul és a motorok tápegységének negatív pólusa közös földre kell, hogy legyen kötve.

---

## GPIO kiosztás (BCM)
| Funkció | GPIO |
|------|------|
| Vízszivattyú PWM | GPIO13 |
| Légszivattyú PWM | GPIO12 |
| MUX S0 | GPIO17 |
| MUX S1 | GPIO27 |
| MUX S2 | GPIO22 |
| MUX S3 | GPIO23 |
| I2C SDA | GPIO2 |
| I2C SCL | GPIO3 |

---

## Bekötési útmutató — röviden
- I²C eszközök: SDA → GPIO2, SCL → GPIO3
- Analóg szenzorok → ADC-16CH → ADS1115 → I²C
- PWMMOS-4 IN bemenetek → GPIO12 / GPIO13
- Motorok tápja külön 5V-ról, de GND közös

---

## Tápellátás és WAGO csoportosítás
- WAGO 1: 3.3V (szenzorok)
- WAGO 2: 5V (motorok)
- WAGO 3: GND (minden eszköz)
- WAGO 4: SDA
- WAGO 5: SCL

---

## Fizikai rögzítés
- Hardware rész alulról 3 db 4×16 mm csavarral rögzítve
- Modulok távtartóval csavarozva
- WAGO sorkapcsok fix rögzítése ajánlott

---

## Adatnaplózás
Log fájl: `/home/dev/oasis/data.txt`
5 MB felett automatikus archiválás történik.

---

## Telepítés és függőségek
```bash
sudo apt update && sudo apt install -y python3-pip i2c-tools
pip3 install adafruit-circuitpython-bme280 adafruit-circuitpython-bh1750 sensirion-scd4x adafruit-circuitpython-ads1x15 RPi.GPIO
```

---

## Indítás / systemd példa
```bash
python3 oasis.py
```

---

## Troubleshooting
- I2C eszköz nem látszik → ellenőrizd a GND-t és SDA/SCL-t
- Zajos ADC mérés → válaszd szét a motor és szenzor kábeleket
- PWM nem működik → közös GND hiányzik

---

## 3D nyomtatható ház — rövid logika
- Alsó egység: víztartály + hardware
- Középső elválasztó tömítéssel
- Felső keret: föld + szenzorok
- Anyag: PETG ajánlott

---



## Tutorial (eredeti, változatlan)

1. GPIO kiosztás 

Vízpumpa PWM: GPIO 13 (BCM)

Légpumpa PWM: GPIO 12 (BCM)

Multiplexer vezérlés: négy vezérlő láb (Address 0..3) a CD74HC4067 multiplexerhez: S0–GPIO17, S1–GPIO27, S2–GPIO22, S3–GPIO23. Az EN (enable) lábat kösd földhöz (aktív alacsony). A multiplexer VCC-re tegyél 3.3V-ot, GND-t pedig közös földre.

I²C busz: SDA – GPIO 2, SCL – GPIO 3 (pin3, pin5), mindkettőre van 1.8–2 kΩ felhúzó a Pi-n. Az I²C-készülékek 3.3V-os logikájúak, így használhatsz 3.3V-os VCC-t vagy 5V-ot, de figyelj, hogy a Pi GPIO lábak nem 5V-tűrők.

2. Modulok és érzékelők bekötése

Multiplexer és analóg szenzorok

SOILCAP-V20 talajnedvesség-érzékelők (3×): Minden szenzor VCC-jét kösd 3.3V-hoz, GND-jét a közös földhöz, az analóg kimenetet (AO) pedig a multiplexer egy-egy csatornájára (például C0, C1, C2) csatlakoztasd. A CD74HC4067 multiplexerrel akár 16 analóg jelet tudsz váltani, így talajnedvesség-szenzorok bemeneteként jól használható.

WLD-75 vízszint-érzékelő: Három vezetékes szenzor (VCC, GND, analóg kimenet). Kösd VCC-jét 5V-ra (3–5V megengedett), GND-jét közös földre, az analóg kimenetet pedig pl. a multiplexer C3 csatornájára. A szenzor 0–2.3V között ad feszültséget a vízszint arányában.

ADS1115 A/D konverter (I²C): A multiplexeren kiválasztott analóg jel (a COM kimenet) kerüljön az ADS1115 egyik analóg bemenetére (pl. A0). Kösd az ADS1115 VDD-jét 3.3V-hoz, GND-jét közös földre, SDA-t a GPIO2-höz, SCL-t a GPIO3-hoz. Az ADS1115 ADDR lábát kösd földre, így a cím 0x48 lesz. Ezzel a Pi I²C buszán keresztül olvashatod a multiplexerről érkező analóg értékeket.

Digitális I²C szenzorok

BH1750 fényérzékelők (2×): Mindkét BH1750 modul VCC-jét kössük 3.3V-ra (több modul 5V-ra is, de legyen közös föld), GND-jüket közös földre. Az I²C vonalak legyenek közösek: SDA–GPIO2, SCL–GPIO3. A BH1750 „ADDR” lábával állítható a címe: az egyik szenzor ADDR=GND-re (cím 0x23), a másik ADDR=VCC-re (cím 0x5C). Így ugyanazon I²C buszon két BH1750 is működik.

BME280 (hőmérséklet/nyomás/ páratartalom): Kösd VCC=3.3V, GND=körös föld, SDA=GPIO2, SCL=GPIO3. A legtöbb BME280 breakout-n van egy „SDA/SDO” láb: kösd GND-re, így az I²C címe 0x76 lesz. A csipek belső címe ettől függően 0x76 vagy 0x77 lehet, a GND-hez kötve alapból 0x76. (A fenti RasPi fórumrajz [16†L91-L94] a csatlakoztatást mutatja.)

SCD40 (NDIR CO₂/hőmérséklet/páratartalom szenzor): A SCD-4x sorozat alapértelmezett I²C címe 0x62. Kösd a modult VIN-nel a 3.3V-hoz (a modulra integrált szabályzó van, de nyugodtan használhatod a Pi 3.3V-ját), GND-t a közös földre, SCL-t GPIO3-hoz, SDA-t GPIO2-höz. Így a Pi látni fogja az SCD40-et a 0x62-es címen az I²C-vonalon.

PWMMOS-4 MOSFET-modul (pumpák vezérlése)

A PWMMOS-4 egy 4 csatornás PWM vezérlő MOSFET-es modult kapcsoló kimenetekkel (max. 10A csatornánként). Csatlakoztatás:

A modul VCC bemenetét kössük a motorok 5V-os külső táplálására (tégy egy plusz tápkábelt, ne a Pi 5V-jára). A modul GND-je legyen közös a Pi földjével és a többi eszközzel.

A modul IN1–IN4 bemenetei a PWM jelek fogadására szolgálnak (3…20V TTL PWM jelet várnak). Kösd a Raspberry Pi GPIO13-át pl. az IN1-re (vízpumpa szabályzás), GPIO12-t az IN2-re (levegőpumpa). A GPIO 3.3V-s PWM-jelet optocsatolóval dolgozza fel a modul.

A modul kimeneti csatornái (OUT1–OUT4) kapcsolják a terheléseket. Például az OUT1-re kösd a vízpumpa negatív vezetékét, az OUT2-re pedig a levegőpumpa negatívját; mindkét pumpa pozitív oldala menjen a +5V-os táplálásra. Így az IN bemenet magas PWM esetén a modul lezárja a MOSFET-et, és a pumpa a +5V és GND között áramot kap. Minden kimeneti ágat LED mutatja.

3. Tápellátás és földelés

Motorok táplálása: A víz- és levegőpumpák 5V-os tápját külön tápegységről biztosítsuk, ne a Raspberry Pi 5V-járól. A nagyáramú motorokat ne terheljük a Pi USB-s tápjával vagy 5V pinjével. Fontos, hogy a Pi GPIO-kat se vezessük közvetlenül 5V-ra – ezeket abszolút nem tűri a processzor.

Közös föld: Minden eszköz osztozzon egy közös földön: a Raspberry Pi, a multiplexer, a PWMMOS-4 modul, az összes szenzor, ADC és a motorok tápegységének negatívja legyen összekötve. A közös GND biztosítja a visszatérő áramutat és a feszültségek referenciáját. Enélkül a jelek nem értelmezhetők és a rendszer nem működik helyesen.

Kábelezés csoportosítása: A 3.3V/5V tápvonalakat (VCC), a földvonalakat (GND) és az I²C vonalakat (SDA, SCL) praktikus WAGO-sorkapcsokba kötve összefogni, így átláthatóan csoportosíthatók. A WAGO-ra köthetők például az összes 3.3V-ot igénylő érzékelő tápvezetékei, egy másik blokkba az összes GND, és külön blokkokba SDA/SCL huzalok. A PWM modul tápkábeleit (5V, GND) szintén külön WAGO-ra köthetjük. Az így kialakított közös bekötőpontok megkönnyítik a rendszer kapcsolását.

PWM jelvezérlés: A Pi PWM kimenetei (GPIO12,13) közvetlenül mennek a PWMMOS-4 modul IN lábaira. A modul tápját (5V, GND) a külön 5V-os tápból kapja. Ügyeljünk arra, hogy a motor tápvezetékei ne okozzanak zavarokat (lásd lejjebb).

4. Rögzítési tanácsok

Fizikai rögzítés: A modulokat (pl. PWMMOS-4, esetleges relémodul) érdemes M3-as csavarokkal, távtartókkal rögzíteni. Használj szigetelő alátétet vagy műanyag távtartót, hogy a PCB alsó fémrészei ne érjenek össze más alkatrészekkel vagy fémházzal. Ha van megfelelő szekrény vagy doboz, csavard bele ott.

WAGO sorkapcsok elhelyezése: A WAGO tömböket rögzítsd sínbetéten (DIN sínre pattintva) vagy ragasztópisztollyal/fixerrel stabilan (ne csak a kábel húzza ki őket véletlenül). Így nem akadhatnak ki a drótok.

Kábelek vezetése: A szenzorok és modulok közötti vezetékhossz legyen a szükséges minimum, különösen az analóg és I²C jelek esetén. Hosszú távon érdemes árnyékolt vagy csavart érpárokat használni: a csavart huzal (twisted pair) egyenlő mértékben éri a külső zajt, így csökkenti a méréshibát. A vezérlő- és érzékelővezetékeket kerüld párhuzamosan futtatni a nagyáramú motorvezetékkel, mert a PWM-es kapcsolások erős elektromágneses zajt generálhatnak. Ha mégis keresztezik egymást, akkor 90°-os szögben legyen az átfedés, hogy minimális legyen a közös indukció.

Szenzor kábelek és árnyékolás: Földéljen az árnyékolás a GND-re (csak az egyik oldalon), hogy a fémfonat elvezesse a zavarokat földre. Kerüld a loopok kialakítását; a kábeleket végig feszesen meghúzva kösd be. Ezáltal a mérési pontosság jobb lesz zajos környezetben is.

5. Extra biztonsági figyelmeztetések

Ne 5V-ot kösd a GPIO-ra! A Raspberry Pi GPIO lábai nem 5V-tűrők. Ha véletlenül 5V-ot (vagy annál nagyobb feszültséget, vagy föld alatti szintet) kötünk rájuk, akkor a processzor tönkremehet. Mindig 3.3V-ot használj a GPIO kimeneteken (például PWM jelek esetén, a PWMMOS modul optocsatolója fogad 3–20V-ot is).

Közös föld nélkül nincs működés: Bármilyen jelátvitelhez és érzékeléshez kell egy referenciapont. Ha a Raspberry Pi és a perifériák között nincs összekötött GND, a jel nem záródik vissza, így a kapcsolat nem fog működni, vagy a mérések hamisak lesznek. Tehát minden modul, szenzor és a motorok tápegysége is legyen összekötve egy közös földre.

## Záró gondolatok
Ez a README teljes egészében használható GitHub projekthez. A rendszer stabil működéséhez tartsd be a tápellátási és földelési szabályokat, különösen az 5V és GPIO kérdését.
