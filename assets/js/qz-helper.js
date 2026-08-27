function setupQzSecurity() {
    // Configurazione del certificato pubblico
    qz.security.setCertificatePromise(function (resolve, reject) {
        fetch("../cert/cert.pem", {cache: 'no-store', headers: {'Content-Type': 'text/plain'}})
            .then(function(data) {
                data.ok ? resolve(data.text()) : reject(data.text());
            })
            .catch(err => reject(err));
    });

    // Configurazione della firma digitale
    qz.security.setSignatureAlgorithm("SHA512");
    qz.security.setSignaturePromise(function(toSign) {
        return function(resolve, reject) {
            fetch("../api/sign-message.php?request=" + encodeURIComponent(toSign), {cache: 'no-store', headers: {'Content-Type': 'text/plain'} })
                .then(function(data) { 
                    data.ok ? resolve(data.text()) : reject(data.text()); 
                })
                .catch(err => reject(err));
        };
    });
}