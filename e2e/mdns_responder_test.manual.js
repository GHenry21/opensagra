// Test una tantum: risponde alle query mDNS per "opensagra.local" con l'IP attuale
// della macchina. Serve solo a verificare il concetto (Fase 3, Appendice C/E) -
// non e' pensato per restare in esecuzione permanentemente cosi' com'e'.
const mdns = require('multicast-dns')();
const os = require('os');

const HOSTNAME = 'opensagra.local';

function getLanIp() {
  const nets = os.networkInterfaces();
  // Esclude VPN/overlay (Tailscale, ecc.): vogliamo la vera interfaccia LAN.
  const skipPattern = /tailscale|vpn|zerotier|wireguard/i;
  for (const name of Object.keys(nets)) {
    if (skipPattern.test(name)) continue;
    for (const net of nets[name]) {
      if (net.family === 'IPv4' && !net.internal) {
        return net.address;
      }
    }
  }
  return null;
}

const ip = getLanIp();
console.log(`Rispondo per ${HOSTNAME} -> ${ip}`);

mdns.on('query', (query) => {
  const isForUs = query.questions.some((q) => q.name === HOSTNAME && (q.type === 'A' || q.type === 'ANY'));
  if (isForUs) {
    console.log('Query ricevuta per', HOSTNAME, '- rispondo con', ip);
    mdns.respond({
      answers: [{ name: HOSTNAME, type: 'A', ttl: 120, data: ip }]
    });
  }
});

console.log('Responder mDNS attivo. Ctrl+C per fermare.');
