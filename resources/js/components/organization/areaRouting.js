/**
 * Precedencia del ruteo a áreas de preparación (D240), para PREVISUALIZARLA en pantalla.
 *
 * ## Quién decide
 *
 * El servidor, al capturar cada línea: `app/Modules/Pos/Application/ResolveAreaRoute.php`. Este módulo repite su orden
 * sólo para EXPLICARLO —«Postres no va a ningún área», «si quitas esta regla, Cervezas irá a la barra»—; nada de lo que
 * calcula se envía al servidor ni decide a dónde sale una comanda. Si la precedencia cambia allá, cambia aquí: es la
 * única copia en el frontend, y por eso vive en un solo archivo.
 *
 * En la sucursal de la cuenta, gana lo primero que exista:
 *
 * 1. la regla del ARTÍCULO;
 * 2. la de su CATEGORÍA y, si ésta no tiene, la de la categoría PADRE (el árbol tiene exactamente dos niveles);
 * 3. nada: el artículo no genera comanda — no sale en ninguna impresora ni tablero.
 *
 * No existe una regla «por defecto» de la sucursal: lo que no coincide con ninguna regla no va a ningún área.
 */

/**
 * El árbol de `/article-categories` (raíces con sus `children`) indexado por ULID, con el padre de cada nodo.
 *
 * @param {Array<{ulid: string, name: string, status: string, children?: Array<object>}>} tree
 * @returns {Map<string, {node: object, parent: object|null}>}
 */
export function indexCategories(tree) {
    const index = new Map();

    for (const root of tree ?? []) {
        index.set(root.ulid, { node: root, parent: null });

        for (const child of root.children ?? []) {
            index.set(child.ulid, { node: child, parent: root });
        }
    }

    return index;
}

/** «Bebidas» o «Bebidas › Cervezas»: una subcategoría sola no dice de dónde cuelga. */
export function categoryLabel(category, index) {
    const parent = index.get(category.ulid)?.parent;

    return parent ? `${parent.name} › ${category.name}` : category.name;
}

/**
 * Las reglas de UNA sucursal, por lo que apuntan. La clase de regla la dice el servidor (`is_article_override`); no se
 * deduce de qué llave viene vacía.
 */
export function splitRules(rules) {
    const byArticle = new Map();
    const byCategory = new Map();

    for (const rule of rules ?? []) {
        if (rule.is_article_override) {
            if (rule.article) {
                byArticle.set(rule.article.ulid, rule);
            }
        } else if (rule.category) {
            byCategory.set(rule.category.ulid, rule);
        }
    }

    return { byArticle, byCategory };
}

/**
 * La regla que decide por CATEGORÍA (paso 2): la de la categoría y, si no tiene, la de su padre. `null` = ninguna.
 *
 * Sólo es fiel con el árbol cargado: sin él no se sabe quién es el padre, y quien llama no debe previsualizar.
 */
export function categoryRuleFor(categoryUlid, index, byCategory) {
    if (!categoryUlid) {
        return null;
    }

    const own = byCategory.get(categoryUlid);

    if (own) {
        return own;
    }

    const parent = index.get(categoryUlid)?.parent;

    return parent ? (byCategory.get(parent.ulid) ?? null) : null;
}

/** Las subcategorías activas de una raíz que tienen regla propia (no dependen de la de su padre). */
function childrenWithOwnRule(root, byCategory) {
    return (root.children ?? [])
        .filter((child) => child.status === 'active' && byCategory.has(child.ulid))
        .map((child) => child.name);
}

/**
 * «Todo lo demás»: las categorías activas cuyos artículos no van a ningún área (sin regla propia ni de su padre).
 *
 * Se agrupa por raíz para que se lea como se piensa: «Postres» entera, o «Bebidas (menos Cervezas)» cuando algunas de
 * sus subcategorías sí tienen regla.
 *
 * @returns {Array<{ulid: string, name: string, except: string[]}>}
 */
export function uncoveredCategories(tree, byCategory) {
    const uncovered = [];

    for (const root of tree ?? []) {
        if (root.status !== 'active' || byCategory.has(root.ulid)) {
            continue;
        }

        uncovered.push({ ulid: root.ulid, name: root.name, except: childrenWithOwnRule(root, byCategory) });
    }

    return uncovered;
}

/**
 * Qué decide la precedencia cuando una regla desaparece, para decirlo ANTES de quitarla.
 *
 * - `article`: el artículo vuelve a su categoría; sin saber cuál es (la regla no la trae), no se puede nombrar el área.
 * - `child`: una subcategoría cae en la regla de su padre, si la tiene (`fallback`); si no, en nada.
 * - `root`: una categoría raíz no tiene de quién heredar; sólo conservan área sus subcategorías con regla propia.
 * - `unknown`: sin el árbol de categorías no se sabe si es raíz o subcategoría.
 */
export function removalOutcome(rule, index, byCategory) {
    if (rule.is_article_override) {
        return { kind: 'article' };
    }

    const entry = index.get(rule.category?.ulid);

    if (!entry) {
        return { kind: 'unknown' };
    }

    if (entry.parent) {
        return { kind: 'child', parent: entry.parent, fallback: byCategory.get(entry.parent.ulid) ?? null };
    }

    return { kind: 'root', keep: childrenWithOwnRule(entry.node, byCategory) };
}
