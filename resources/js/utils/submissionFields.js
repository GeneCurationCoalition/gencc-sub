/**
 * How a submission's reference fields (gene, disease, inheritance,
 * classification) are displayed when their submitted value did not resolve.
 *
 * An unresolved field has no linked record: the server leaves the foreign key
 * empty and records an error under the field's key in submission_errors.
 * Views must then show what was submitted, from submission_data, marked as
 * unresolved, and never a value the record does not hold.
 */

/**
 * For each relation: its key in submission_errors, and its key in
 * submission_data, where the submitted {id, name} is kept.
 */
const REFERENCE_FIELDS = {
    gene: { errorKey: 'gene_hgnc_id', submittedKey: 'gene' },
    disease: { errorKey: 'disease_curie_id', submittedKey: 'disease' },
    inheritance: { errorKey: 'moi_curie_id', submittedKey: 'moi' },
    classification: { errorKey: 'classification_curie_id', submittedKey: 'classification' },
};

/**
 * The unresolved state of one reference field, or null when it resolved.
 *
 * @param {object} submission - A submission with submission_errors and submission_data
 * @param {string} field - One of: gene, disease, inheritance, classification
 * @returns {{message: string, id: string, name: string}|null}
 */
export function unresolvedField(submission, field) {
    const { errorKey, submittedKey } = REFERENCE_FIELDS[field];
    const message = submission?.submission_errors?.[errorKey];

    if (!message) {
        return null;
    }

    const submitted = submission.submission_data?.[submittedKey] ?? {};

    return {
        message,
        id: submitted.id ? String(submitted.id) : '',
        name: submitted.name ? String(submitted.name) : '',
    };
}
