# Procedura — mAP lite come bridge WiFi verso ChateauLTE (station-bridge)

> Collegare una stampante LAN (Ethernet) a un MikroTik **mAP lite** che fa da
> ponte WiFi verso il router principale **ChateauLTE**. Il mAP non fa da router:
> è un **bridge di livello 2**, i dispositivi dietro di lui prendono l'IP
> direttamente dal DHCP di ChateauLTE.
>
> Testato il 2026-09-02. Include il resoconto di cosa era andato storto e la
> procedura pulita da rifare in caso di reset.

---

## Topologia

```
[ ChateauLTE ]  --WiFi 2.4GHz-->  [ mAP lite ]  --cavo LAN-->  [ Stampante ]
 192.168.88.1        (station-bridge)   wlan1 + ether1            IP da ChateauLTE
 DHCP server                            in bridge1                (es. 192.168.88.x)
```

| Elemento | Valore |
|---|---|
| Router principale | ChateauLTE, RouterOS, gateway **192.168.88.1**, DHCP server della LAN |
| mAP lite — ruolo | bridge WiFi trasparente (L2), **non** router, **niente NAT** |
| mAP lite — interfacce | `wlan1` in **station-bridge** + `ether1`, entrambe dentro `bridge1` |
| mAP lite — IP di gestione | assegnato da DHCP di ChateauLTE (es. **192.168.88.23**), **non** statico |
| Stampante | cavo su `ether1` del mAP → ottiene IP dal DHCP di ChateauLTE |

Perché **station-bridge** e non "station" semplice: la modalità `station` normale
non fa passare il MAC dei client dietro la radio (niente bridging L2 trasparente).
`station-bridge` sì, ma è proprietaria MikroTik — funziona perché **entrambi i capi
sono MikroTik**.

---

## Cosa era andato storto (2026-09-02)

C'erano **due problemi indipendenti e sovrapposti**, non uno causato dall'altro.

### Problema 1 — IP statico di fabbrica = IP del gateway

Il mAP di fabbrica ha `192.168.88.1/24` statico su `bridge1`. Ma `192.168.88.1`
è **anche** l'indirizzo di ChateauLTE (il gateway).

Conseguenze:

- La default route imparata via DHCP (`0.0.0.0/0` via `192.168.88.1`) restava
  **INACTIVE** (`DId` in `/ip route print`): RouterOS non installa una rotta verso
  un proprio indirizzo locale → `ping 1.1.1.1` = **"no route to host"**.
- `ping 192.168.88.1` "funzionava" perché il mAP **pingava sé stesso** (risposta
  immediata, non ChateauLTE).

### Problema 2 — client DHCP incastrato

Il client DHCP era **già bloccato dall'inizio**: `status=bound` con
`address=192.168.88.23/24`, ma quell'indirizzo **non veniva mai applicato**
davvero all'interfaccia.

- Prova: il primo `/ip address print` mostrava **solo** il `.1` statico, nessun
  `.23` dinamico, pur con lo status `bound`.
- Un client DHCP sano mostra sempre il suo indirizzo dinamico (flag `D`) in
  `/ip address print`.
- Il `.1` statico **mascherava** il problema: dava comunque un indirizzo
  funzionante nella stessa subnet, quindi LAN e stampante rispondevano.

### "Se avessi tolto subito il .1, avrei evitato tutto?"

**No.** Togliere subito il `.1` **non** sarebbe bastato:

- Avrebbe **smascherato immediatamente** il Problema 2: il mAP si sarebbe trovato
  **senza alcun IP** (`/ip address print` vuoto, sparita anche la rotta connected)
  → nessuna connettività, nemmeno la LAN.
- Sarebbe comunque servito `remove` + `add` del client DHCP: il `renew` **non**
  sblocca quello stato, solo la ricreazione lo fa.

Quello che sarebbe cambiato: la **diagnosi più veloce**. Il sintomo sarebbe stato
netto ("non va niente") invece dell'ambiguo "metà funziona".

> In un setup pulito con client DHCP **sano**, l'unico bug sarebbe stato il `.1`
> statico, e rimuoverlo sarebbe stata la soluzione completa: il client sano
> avrebbe già avuto il `.23` applicato *accanto* al `.1`, e la default route si
> attiva appena sparisce il `.1` locale in conflitto.

### Perché il client DHCP si era incastrato

Non è certo al 100%. Ipotesi più probabile: la procedura seguita ha creato il
client DHCP **mentre `bridge1` aveva già il `.1` in conflitto**, lasciandolo in
uno stato mezzo-inizializzato. La ricreazione pulita lo ha risolto.

---

## Procedura corretta (da zero / dopo un reset)

Parti dalla configurazione di fabbrica del mAP (ha già `bridge1` con `ether1` e
`wlan1` dentro).

### 1. Connettiti in modo sicuro

- [ ] Winbox → tab **Neighbors** → clicca sull'indirizzo **MAC** del mAP
      (non sull'IP). Così la sessione **non cade** quando cambi indirizzi.

### 2. Radio in station-bridge

Legacy (pacchetto `wireless`, tipico su mAP lite):

```
/interface wireless security-profiles set default \
    mode=dynamic-keys authentication-types=wpa2-psk \
    wpa2-pre-shared-key=<PASSWORD_ChateauLTE>

/interface wireless set wlan1 \
    mode=station-bridge ssid=<SSID_ChateauLTE> \
    security-profile=default disabled=no
```

wifiwave2 (`/interface/wifi`), se il mAP usa quel pacchetto:

```
/interface/wifi/security add name=sec-chateau \
    authentication-types=wpa2-psk passphrase=<PASSWORD_ChateauLTE>
/interface/wifi/configuration add name=cfg-sta \
    mode=station ssid=<SSID_ChateauLTE> security=sec-chateau
/interface/wifi set wlan1 configuration=cfg-sta disabled=no
```

### 3. Bridge: ether1 + wlan1 insieme

```
/interface bridge port print
```

- [ ] Verifica che **sia `ether1` sia `wlan1`** abbiano `bridge=bridge1`.
      Se manca uno: `/interface bridge port add bridge=bridge1 interface=<if>`.

### 4. ⚠️ Rimuovi l'IP statico di fabbrica — PRIMA del DHCP client

```
/ip address print
/ip address remove [find where interface=bridge1 && dynamic=no]
```

Questo è il passo che era stato saltato. Il `192.168.88.1/24` statico **deve
sparire** perché coincide con il gateway ChateauLTE.

### 5. Client DHCP su bridge1

```
/ip dhcp-client add interface=bridge1 add-default-route=yes use-peer-dns=yes disabled=no
```

Se stai sistemando un mAP dove il DHCP client **esiste già** ed è in stato strano
(`bound` ma senza indirizzo in `/ip address print`): **non fare `renew`**, ma
ricrealo —

```
/ip dhcp-client remove [find interface=bridge1]
/ip dhcp-client add interface=bridge1 add-default-route=yes use-peer-dns=yes disabled=no
```

### 6. Prenota l'IP del mAP su ChateauLTE

- [ ] Su ChateauLTE: `/ip dhcp-server lease` → **make static** il lease del mAP
      (o aggiungi una reservation al MAC di `bridge1` del mAP). Così lo ritrovi
      sempre allo stesso indirizzo.

### 7. Collega la stampante

- [ ] Cavo dalla stampante a **`ether1`** del mAP.
- [ ] La stampante prende IP dal DHCP di ChateauLTE. Prenota anche il suo lease
      (make static) così l'IP della stampante non cambia.

---

## Verifica — criterio di accettazione

```
/ip address print
```
Atteso: **una riga con flag `D`**, es. `D 192.168.88.23/24 ... bridge1`.
Nessun indirizzo statico.

```
/ip route print
```
Atteso, **entrambe ACTIVE** (nessun flag `I`):

```
DAd  0.0.0.0/0        192.168.88.1   main   1
DAc  192.168.88.0/24  bridge1        main   0
```

```
/ping 1.1.1.1          → risponde
/ping 8.8.8.8          → risponde
/ping google.com       → risponde (verifica anche il DNS)
```

Dal PC in LAN:

```
ping <IP_stampante>    → risponde
```

E la stampa dal gestionale funziona.

---

## Troubleshooting rapido

| Sintomo | Causa probabile | Fix |
|---|---|---|
| `ping 1.1.1.1` → **no route to host**, ma `ping 192.168.88.1` ok | Default route **INACTIVE** (`DId`). Spesso: IP statico locale = IP gateway | `/ip route print` → se `0.0.0.0/0` ha flag `I`, togli l'IP statico da `bridge1` (passo 4) |
| `ping 192.168.88.1` risponde istantaneo / 0ms | Stai pingando il mAP stesso: ha ancora `192.168.88.1/24` statico | `/ip address remove [find where interface=bridge1 && dynamic=no]` |
| `/ip dhcp-client print` dice `status=bound` ma `/ip address print` non ha l'indirizzo dinamico | Client DHCP incastrato | `remove` + `add` del client (non `renew`) |
| Dopo aver tolto l'IP statico, `/ip address print` è **vuoto** | Client DHCP incastrato, era mascherato dallo statico | `remove` + `add` del client DHCP |
| LAN ok ma il DHCP non aggancia mai | Link wireless L2 non passa dati | `/interface wireless registration-table print` (o `/interface/wifi/registration-table print`); verifica SSID/password e che `wlan1` sia `station-bridge` e `running` |
| Persi Winbox dopo `address remove` | Eri connesso via IP | Riconnetti via **MAC** dalla tab Neighbors |
| La stampante non prende IP | `ether1` non è in `bridge1`, o cavo/porta | `/interface bridge port print`; controlla il cavo su `ether1` |

---

## Note

- **Niente NAT / masquerade** sul mAP: è un bridge L2, non un router. Se una
  procedura copiata ha aggiunto regole di masquerade, **rimuovile**.
- `default-route-tables=default` nel client DHCP **va bene** (è il valore normale
  in RouterOS 7, corrisponde alla tabella `main`) — non modificarlo.
- La config di RouterOS è **persistente**: nessun "save" manuale, sopravvive al
  riavvio.
- Collegato al test della stampante reale per il fix del metodo di pagamento in
  billing (vedi memoria di progetto).
