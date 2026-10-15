// schemacheck: controllo automatico della regola D del piano (Fase 6c) -
// le modifiche allo schema del database devono essere compatibili con la
// versione precedente, perche' per qualche minuto (o per una serata, se una
// cassa non si aggiorna) la cassa centrale e' nuova e le casse vecchie, sullo
// STESSO database. Solo aggiunte: tabelle nuove, colonne con un default.
//
// Vietati, sia nelle migrazioni (config/migrations/) sia negli ALTER a
// runtime (ensure* nel codice PHP, battito nel wrapper Go):
//   - DROP TABLE / COLUMN / INDEX / KEY / DATABASE, TRUNCATE TABLE
//   - RENAME TABLE / COLUMN / TO
//   - CHANGE / MODIFY di una colonna (rinomina o restringe: non si puo'
//     capire staticamente quale, quindi vanno giustificati uno per uno)
//   - ADD COLUMN ... NOT NULL senza DEFAULT (le INSERT del codice vecchio,
//     che non conosce la colonna, fallirebbero)
//
// Un'eccezione e' ammessa solo esplicita e motivata: un commento
// "schema-compat: ok - <motivo>" sulla stessa riga dell'istruzione, o su
// una riga di solo commento appena sopra (es. tabelle di appoggio locali,
// migrazioni storiche che agiscono solo su database precedenti alla prima
// release).
//
// Uso (CI: .github/workflows/schema-check.yml):
//
//	cd wrapper && go run ./cmd/schemacheck -root ..
//
// Esce con 1 se trova violazioni; in GitHub Actions le stampa anche come
// annotazioni (::error file=...,line=...::).
package main

import (
	"bufio"
	"flag"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
)

// Cartelle con codice che parla col database. Fuori: vendor/ (librerie),
// e2e/ (test), docs/, config/pos.sql (schema completo di un'installazione
// nuova: li' non c'e' codice vecchio da proteggere).
var scanDirs = []string{"api", "config", "print", "bin", "pages", "includes", "wrapper"}

const marker = "schema-compat: ok"

type rule struct {
	re  *regexp.Regexp
	msg string
}

var rules = []rule{
	{regexp.MustCompile(`(?i)\bDROP\s+(TABLE|COLUMN|INDEX|KEY|DATABASE|SCHEMA|PRIMARY\s+KEY|FOREIGN\s+KEY)\b`),
		"DROP: elimina struttura che il codice della versione precedente puo' ancora usare"},
	{regexp.MustCompile(`(?i)\bTRUNCATE\s+TABLE\b`),
		"TRUNCATE TABLE: cancella dati condivisi"},
	{regexp.MustCompile(`(?i)\bRENAME\s+(TABLE|COLUMN|TO)\b`),
		"RENAME: il codice della versione precedente cerca ancora il nome vecchio"},
	{regexp.MustCompile(`(?i)\bALTER\s+TABLE\b.*\b(CHANGE|MODIFY)(\s+COLUMN)?\s+[` + "`" + `\w]`),
		"CHANGE/MODIFY: rinomina o cambia tipo a una colonna esistente; ammesso solo se allarga (va motivato)"},
}

// ADD COLUMN <nome> <tipo...> NOT NULL senza DEFAULT nella stessa definizione.
var addColumn = regexp.MustCompile(`(?i)\bADD\s+(COLUMN\s+)?(IF\s+NOT\s+EXISTS\s+)?[` + "`" + `\w]+\s+[^,;"']*`)
var notNull = regexp.MustCompile(`(?i)\bNOT\s+NULL\b`)
var hasDefault = regexp.MustCompile(`(?i)\bDEFAULT\b|\bAUTO_INCREMENT\b`)
var alterTable = regexp.MustCompile(`(?i)\bALTER\s+TABLE\b`)

type violation struct {
	file string
	line int
	msg  string
	text string
}

func main() {
	root := flag.String("root", "..", "radice del repository")
	flag.Parse()

	var found []violation
	for _, d := range scanDirs {
		base := filepath.Join(*root, d)
		_ = filepath.WalkDir(base, func(p string, e fs.DirEntry, err error) error {
			if err != nil {
				return nil
			}
			if e.IsDir() {
				if n := e.Name(); n == "vendor" || n == "node_modules" || n == "logs" {
					return filepath.SkipDir
				}
				return nil
			}
			ext := strings.ToLower(filepath.Ext(p))
			if ext != ".php" && ext != ".go" {
				return nil
			}
			rel, _ := filepath.Rel(*root, p)
			rel = filepath.ToSlash(rel)
			if strings.HasPrefix(rel, "wrapper/cmd/schemacheck/") {
				return nil // questo file elenca le parole vietate
			}
			found = append(found, scanFile(p, rel)...)
			return nil
		})
	}

	sort.Slice(found, func(i, j int) bool {
		if found[i].file != found[j].file {
			return found[i].file < found[j].file
		}
		return found[i].line < found[j].line
	})
	gha := os.Getenv("GITHUB_ACTIONS") == "true"
	for _, v := range found {
		fmt.Printf("%s:%d: %s\n    %s\n", v.file, v.line, v.msg, v.text)
		if gha {
			fmt.Printf("::error file=%s,line=%d::%s\n", v.file, v.line, v.msg)
		}
	}
	if len(found) > 0 {
		fmt.Printf("\n%d modifiche di schema non compatibili con la versione precedente (regola D, piano Fase 6c).\n"+
			"Solo aggiunte: tabelle nuove, colonne con DEFAULT. Se una e' davvero sicura, motivala con un commento\n"+
			"\"%s - <motivo>\" sulla stessa riga o su quella sopra.\n", len(found), marker)
		os.Exit(1)
	}
	fmt.Println("schema: nessuna modifica incompatibile con la versione precedente")
}

func isCommentLine(t string) bool {
	return strings.HasPrefix(t, "//") || strings.HasPrefix(t, "#") ||
		strings.HasPrefix(t, "*") || strings.HasPrefix(t, "/*")
}

// continues: la stringa SQL prosegue sulla riga dopo (concatenazione PHP "."
// o Go "+", o argomento successivo).
func continues(t string) bool {
	return strings.HasSuffix(t, "+") || strings.HasSuffix(t, ".") || strings.HasSuffix(t, ",") ||
		strings.HasSuffix(t, `"`) || strings.HasSuffix(t, `'`)
}

func scanFile(path, rel string) []violation {
	f, err := os.Open(path)
	if err != nil {
		return nil
	}
	defer f.Close()
	var lines []string
	sc := bufio.NewScanner(f)
	sc.Buffer(make([]byte, 1024*1024), 1024*1024)
	for sc.Scan() {
		lines = append(lines, sc.Text())
	}

	var out []violation
	for i := 0; i < len(lines); i++ {
		t := strings.TrimSpace(lines[i])
		if t == "" || isCommentLine(t) {
			continue
		}
		// Eccezione: marcatore sulla stessa riga, o su quella sopra solo se
		// e' un commento a se' (un marcatore in coda a un'altra istruzione
		// vale solo per quella).
		allowed := strings.Contains(lines[i], marker) ||
			(i > 0 && strings.Contains(lines[i-1], marker) && isCommentLine(strings.TrimSpace(lines[i-1])))

		for _, r := range rules {
			if r.re.MatchString(lines[i]) && !allowed {
				out = append(out, violation{rel, i + 1, r.msg, t})
			}
		}

		// ADD COLUMN NOT NULL senza DEFAULT: l'istruzione puo' continuare
		// sulle righe dopo (stringhe concatenate), fino a 6.
		if alterTable.MatchString(lines[i]) {
			stmt := t
			for j := i; j < len(lines)-1 && j < i+6 && continues(strings.TrimSpace(lines[j])); j++ {
				next := strings.TrimSpace(lines[j+1])
				if isCommentLine(next) {
					break
				}
				stmt += " " + next
			}
			for _, def := range addColumn.FindAllString(stmt, -1) {
				if notNull.MatchString(def) && !hasDefault.MatchString(def) && !allowed {
					out = append(out, violation{rel, i + 1,
						"ADD COLUMN ... NOT NULL senza DEFAULT: le INSERT del codice della versione precedente fallirebbero", t})
				}
			}
		}
	}
	return out
}
