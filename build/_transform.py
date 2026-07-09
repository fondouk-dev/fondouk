#!/usr/bin/env python3
"""
Transformations de packaging Fondouk (appelé par build/package.sh).

Sous-commandes :
  rename   <module_dir> <LOWER> <PASCAL> <UPPER>
      Renomme les identifiants PrestaShop (technique/classe/namespace/URL/config/tables)
      dans les .php + composer.json, SANS toucher au namespace du cœur `Fondouk\\CapabilityCore`
      ni au package Composer `fondouk/capability-core`.

  finalize <module_dir> <PASCAL> <AUTHOR> <YEAR>
      composer.json minimal (retire repository path + require, garde autoload/config),
      stub index.php dans CHAQUE dossier (y compris vendor/), en-tête licence MIT dans
      chaque .php qui n'en a pas.
"""
import os
import re
import sys
import json

STUB = (
    "<?php\n"
    "/**\n"
    " * Fondouk — AI Agent Ready for PrestaShop (UCP, MCP).\n"
    " *\n"
    " * @author    Synapsea\n"
    " * @copyright 2026 Synapsea\n"
    " * @license   https://opensource.org/licenses/MIT MIT License\n"
    " */\n"
    "header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');\n"
    "header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');\n"
    "header('Cache-Control: no-store, no-cache, must-revalidate');\n"
    "header('Cache-Control: post-check=0, pre-check=0', false);\n"
    "header('Pragma: no-cache');\n"
    "header('Location: ../');\n"
    "exit;\n"
)


def walk_php(root):
    for dp, _, fns in os.walk(root):
        for fn in fns:
            if fn.endswith(".php"):
                yield os.path.join(dp, fn)


def rename(module_dir, lower, pascal, upper):
    for path in walk_php(module_dir):
        s = open(path, encoding="utf-8").read()
        # Pascal : préserve le namespace du cœur `Fondouk\CapabilityCore`.
        s = re.sub(r"Fondouk(?!\\CapabilityCore)", pascal, s)
        s = s.replace("FONDOUK", upper)
        s = s.replace("fondouk", lower)
        open(path, "w", encoding="utf-8").write(s)

    cj = os.path.join(module_dir, "composer.json")
    if os.path.isfile(cj):
        s = open(cj, encoding="utf-8").read()
        # Uniquement le namespace PSR-4 du module (JSON : Module\\Fondouk\\).
        s = s.replace("Module\\\\Fondouk", "Module\\\\" + pascal)
        open(cj, "w", encoding="utf-8").write(s)
    print(f"[rename] {lower}/{pascal}/{upper} appliqué")


def finalize(module_dir, pascal, author, year):
    # 1. composer.json minimal (vendor bundlé → plus de repo path ni de require).
    cj = os.path.join(module_dir, "composer.json")
    if os.path.isfile(cj):
        data = json.load(open(cj, encoding="utf-8"))
        minimal = {
            "name": data.get("name", "fondouk/prestashop-module"),
            "type": "prestashop-module",
            "license": "MIT",
            "autoload": data.get("autoload", {}),
            "config": {"prepend-autoloader": False},
        }
        json.dump(minimal, open(cj, "w", encoding="utf-8"), indent=4, ensure_ascii=False)

    # 2. stub index.php dans CHAQUE dossier, TOUJOURS réécrit au gabarit (source de
    #    vérité unique) : tous les stubs du zip sont identiques et php-cs-fixer-clean
    #    (ligne vide après <?php, docbloc, code collé), quoi qu'il y ait eu en source.
    written = 0
    for dp, _, fns in os.walk(module_dir):
        open(os.path.join(dp, "index.php"), "w", encoding="utf-8").write(STUB)
        written += 1

    # 3. en-tête licence MIT dans chaque .php qui n'en a pas.
    header = (
        "/**\n"
        f" * @author    {author}\n"
        f" * @copyright {year} {author}\n"
        " * @license   https://opensource.org/licenses/MIT MIT License\n"
        " */\n"
    )
    stamped = 0
    for path in walk_php(module_dir):
        # les stubs index.php sont des redirections : pas d'en-tête licence (le sniff
        # « file doc comment » ne l'exige pas, et un docbloc y casserait blank_line_after_opening_tag).
        if os.path.basename(path) == "index.php":
            continue
        lines = open(path, encoding="utf-8").read().split("\n")
        if any("@license" in ln for ln in lines[:15]):
            continue
        # point d'insertion : JUSTE après <?php (position reconnue par le sniff
        # « file doc comment » de PrestaShop — avant declare/namespace, jamais après,
        # sinon la première chose après <?php n'est pas un docbloc et le sniff échoue).
        insert = 1
        lines[insert:insert] = header.rstrip("\n").split("\n")
        open(path, "w", encoding="utf-8").write("\n".join(lines))
        stamped += 1

    print(f"[finalize] composer.json minimal, {written} index.php (gabarit), {stamped} en-têtes licence")


if __name__ == "__main__":
    cmd = sys.argv[1]
    if cmd == "rename":
        rename(sys.argv[2], sys.argv[3], sys.argv[4], sys.argv[5])
    elif cmd == "finalize":
        finalize(sys.argv[2], sys.argv[3], sys.argv[4], sys.argv[5])
    elif cmd == "stub":
        # Source de vérité du gabarit de stub, pour le contrôle par diff dans package.sh.
        sys.stdout.write(STUB)
    else:
        sys.exit("commande inconnue: " + cmd)
