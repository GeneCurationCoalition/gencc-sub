/**
 * Helper functions for generating external links to disease and gene databases
 */

/**
 * Generate URL for a disease CURIE
 * @param {string} curie - Disease CURIE (e.g., "MONDO:0012345", "OMIM:123456", "Orphanet:12345")
 * @returns {string|null} - External URL or null if invalid
 */
export function getDiseaseUrl(curie) {
    if (!curie || typeof curie !== 'string') {
        return null;
    }

    const upperCurie = curie.toUpperCase();

    // MONDO: https://monarchinitiative.org/MONDO:12345
    if (upperCurie.startsWith('MONDO:')) {
        return `https://monarchinitiative.org/${curie.toUpperCase()}`;
    }

    // OMIM: https://omim.org/entry/12345
    if (upperCurie.startsWith('OMIM:')) {
        const omimId = curie.split(':')[1];
        return `https://omim.org/entry/${omimId}`;
    }

    // Orphanet: https://www.orpha.net/en/disease/detail/12345
    if (upperCurie.startsWith('ORPHANET:')) {
        const orphanetId = curie.split(':')[1];
        return `https://www.orpha.net/en/disease/detail/${orphanetId}`;
    }

    return null;
}

/**
 * Generate URL for an HGNC gene ID
 * @param {string} hgncId - HGNC ID (e.g., "HGNC:12345" or just "12345")
 * @returns {string|null} - External URL or null if invalid
 */
export function getGeneUrl(hgncId) {
    if (!hgncId) {
        return null;
    }

    // Normalize to uppercase and ensure HGNC: prefix
    let normalizedId = String(hgncId).toUpperCase();
    if (!normalizedId.startsWith('HGNC:')) {
        normalizedId = `HGNC:${hgncId}`;
    }

    // https://www.genenames.org/data/gene-symbol-report/#!/hgnc_id/HGNC:12345
    return `https://www.genenames.org/data/gene-symbol-report/#!/hgnc_id/${normalizedId}`;
}
