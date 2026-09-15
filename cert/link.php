<?php
// Pagina pensata per essere aperta da un cellulare/tablet (via QR code da
// Configurazione Rete, pages/conf_rete.php), non dal PC che gestisce le
// casse: niente sidebar/header, e va servita in HTTP puro (il blocco :80 del
// Caddyfile) perche' il suo scopo e' proprio il primo contatto di un
// dispositivo che non si fida ancora del certificato HTTPS di questo PC.
// Vedi lo stesso calcolo in pages/conf_rete.php per la spiegazione del
// passaggio intermedio (dirname() su Windows ripiega su "\" a fine risalita).
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$appBasePath = rtrim(str_replace('\\', '/', dirname($scriptDir)), '/');
$hostNoPort = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
$httpsHomeUrl = 'https://' . $hostNoPort . $appBasePath . '/pages/index.php';
?>
<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../assets/css/pos-redesign.css">
    <link rel="stylesheet" href="../assets/css/cert.css">
    <?php include __DIR__ . '/../includes/head-favicons.php'; ?>
    <?php require_once __DIR__ . '/../includes/icons.php'; ?>
    <title>Collega questo dispositivo</title>
    <script src="../assets/js/theme.js"></script>
</head>

<body class="management-page cert-page">
    <main class="cert-shell">
        <h1 class="cert-title">
            <?= pos_icon('network', ['class' => 'cert-title-icon']) ?>
            Collega questo dispositivo a OpenSagra
        </h1>

        <section class="cert-card">
            <div class="panel-title-row">
                <?= pos_icon('download', ['class' => 'panel-title-icon']) ?>
                <h2>1. Scarica il certificato</h2>
            </div>
            <p class="inline-muted">
                E' il "biglietto da visita" di questo PC: una volta installato, il browser
                smette di segnalare OpenSagra come sito non sicuro. Va fatto una sola volta
                per dispositivo.
            </p>
            <div class="actions-row cert-actions-row">
                <a class="btn-add" href="caddy-root-ca.crt" download="opensagra-ca.crt">
                    <?= pos_icon('download') ?>
                    Scarica certificato
                </a>
            </div>
            <details class="cert-howto">
                <summary>Come si installa?</summary>
                <p class="inline-muted"><strong>Android:</strong> Impostazioni → Sicurezza e
                    privacy → Crittografia e credenziali → Installa certificato → Certificato
                    CA → scegli il file appena scaricato. Dopo l'installazione Android mostra
                    un avviso permanente ("rete monitorata" o simile): e' normale, non un
                    errore.</p>
                <p class="inline-muted"><strong>iPhone/iPad:</strong> dopo il download, apri
                    Impostazioni → in alto comparira' "Profilo scaricato" → Installa. Poi vai
                    in Impostazioni → Generali → Informazioni → Impostazioni certificati
                    attendibili e attiva la piena fiducia per il certificato OpenSagra.</p>
                <p class="inline-muted"><strong>Firefox (telefono o PC):</strong> usa un
                    proprio elenco di certificati, separato da quello del sistema — la prima
                    volta mostrera' comunque un avviso ("Avanzate" → "Accetta il rischio e
                    continua"). E' normale, dopo funziona senza piu' avvisi.</p>
            </details>
        </section>

        <section class="cert-card">
            <div class="panel-title-row">
                <?= pos_icon('house', ['class' => 'panel-title-icon']) ?>
                <h2>2. Apri OpenSagra</h2>
            </div>
            <p class="inline-muted">
                Dopo aver installato il certificato, apri l'app: il lucchetto confermera' che
                questo dispositivo ora si fida di questo PC.
            </p>
            <div class="actions-row cert-actions-row">
                <a class="btn-add" href="<?= htmlspecialchars($httpsHomeUrl) ?>">
                    <?= pos_icon('house') ?>
                    Apri OpenSagra
                </a>
            </div>
            <p class="inline-muted cert-skip">
                Non vuoi installare il certificato ora? Puoi comunque
                <a href="../pages/index.php">aprire OpenSagra senza HTTPS</a>.
            </p>
        </section>
    </main>
</body>

</html>
