# ClinVar vs GenCC submission processing discussion

## Essentials of ClinVar Submission Processing

ClinVar is a publicly accessible database where submitters can share information about genetic variants and their potential impact on health conditions. This document explains how ClinVar organizes and manages these submissions to ensure data consistency, accuracy, and easy tracking.

### Key Concepts

#### Classification
A classification is an expert’s statement about how a specific genetic variant might affect health (for example, whether it could cause disease, influence drug response, or contribute to cancer). Submitters can update these statements as new information becomes available. ClinVar allows submitters to reference their previous submissions and store their own identifiers to easily track changes over time.

**Other terms you might see:** assertion, statement, submission, interpretation.

#### Variant
A genetic variant is the specific DNA change being assessed. Once submitted to ClinVar, this variant must remain constant. If the variant changes, it is considered a new, different classification and requires a separate submission.

**Other terms you might see:** allele, variation, measure.

#### Disease
This refers to the health condition associated with the genetic variant. While the variant must remain constant, submitters can update or refine the disease description as understanding evolves.

**Other terms you might see:** condition, phenotype, trait.

### How ClinVar Identifies and Tracks Submissions

#### Natural Key
A natural key is a way to uniquely identify something using real-world attributes that ideally never change. Although variants might seem perfect for this purpose, diseases can evolve in their definition, so ClinVar treats only the variant as reliably constant. Diseases can change slightly without becoming a completely new record.

#### Primary Key
A primary key is a unique identifier assigned by ClinVar once a submission is validated and accepted. ClinVar uses an "SCV accession number" as its primary key, meaning each classification has a unique SCV ID that identifies it within ClinVar. Other examples include submitter IDs, RCV, and VCV accession numbers.

**Other terms you might see:** unique key, identifier, ID.

#### Local Key
This is an optional identifier managed solely by the submitter, allowing them to connect their own internal records to the ClinVar SCV accession numbers. Using a consistent local key helps submitters manage and track their submissions easily over time.

**Other terms you might see:** submitter’s identifier, external ID.

### Understanding ClinVar Submissions

#### Submission
A submission is the set of information provided to ClinVar about a genetic variant and its associated disease. Submitters typically manage and update these records regularly. Once accepted by ClinVar, each submission is assigned a unique SCV accession ID.

#### Batch Submission
Submitters often send multiple classification records at once, usually through an Excel file uploaded to the ClinVar portal. Each batch can be given a name by the submitter to help track submissions over time.

**Other terms you might see:** submission file.

#### SCV (Submitted ClinVar Variant)
SCV is the unique identifier (primary key) assigned to each validated and processed submission. ClinVar maintains versions of these submissions. Each new or updated classification increments the version number to help track changes clearly.

### Versioning Classifications
Every time a submitter updates a classification, ClinVar creates a new version, assigning a higher version number to clearly track changes over time. Submitters indicate if the submission is new ('N') or an update ('U'). New submissions cannot duplicate an existing variant-disease combination from the same submitter. Updates must keep the same variant but can adjust the disease if needed.

ClinVar encourages submitters to be thoughtful about submitting multiple classifications for the same variant to avoid redundancy unless truly necessary (e.g., different diseases connected to the same variant).

By understanding these core principles, submitters can effectively manage their data in ClinVar, ensuring clear and consistent communication about genetic variants and their clinical implications.

## ClinVar vs GenCC: Validation, Processing, and Releases

### Submitter Community and Influence
GenCC has a smaller, carefully selected group of submitters compared to ClinVar. All GenCC submitters are equally trusted, meaning there's no need for ranking systems such as ClinVar’s star levels. Every GenCC submitter is considered an expert, simplifying submission management.

### Defining a Submission
A GenCC submission represents a classification of **Gene-Disease relationships** with a specified **Mode of Inheritance (MOI)**. Similar to ClinVar, GenCC submitters may initially define a classification and later adjust or refine it.

However, certain key points must remain consistent:

- The **Gene** involved should **never change** once a classification is submitted. Changing the gene would imply an entirely different classification.
- The **Disease** and the **Mode of Inheritance** (MOI) could reasonably be adjusted as the understanding of diseases evolves, and consortium members agree upon standard groupings or labels.

### Validation and Processing Complexity
ClinVar includes extensive validation of fields during submission due to its broad submitter base and varying quality of submissions. By comparison, GenCC’s validation can be simpler, focusing primarily on core attributes such as:

- **Gene**: Must always be provided and valid.
- **Disease**: Should always be provided and validated (no submissions without disease).
- **Mode of Inheritance (MOI)**: Likely required, but may default to "Unknown" if not explicitly provided.
- **Evaluated Date, Classification Code, and Assertion Criteria URL**: These fields are mandatory and require basic validation checks.
- **Supporting Information (Text summaries and PMIDs)**: Minimal validation, but ensuring proper formatting and completeness could be beneficial.

Overall, GenCC’s validation process can be significantly less complex compared to ClinVar.

### Managing Novel vs. Updated Submissions
ClinVar distinguishes between **Novel ('N')** and **Updated ('U')** submissions clearly:

- **Novel submissions** are new and have no existing accession ID.
- **Updated submissions** must specify the existing accession ID and must retain the same variant.

For GenCC, adopting a similar approach by creating and managing unique accession IDs (for example, **SGV IDs**) could simplify record management, validation, and submission processing:

- Each submission would receive a stable, system-generated identifier (SGV accession).
- Submitters would explicitly indicate if a submission is new ('N') or an update ('U'), providing the SGV accession for updates.
- This places an additional but minimal responsibility on submitters. To ease this burden, GenCC could provide easy-to-access reports mapping submitters' own identifiers (local keys) to GenCC SGV IDs.
- Like ClinVar, versioning these identifiers (**accession.version**) would allow precise referencing of historical classification states.

### Data Releases and Versioning
ClinVar performs regular **snapshot releases** to stabilize their dataset for community reference. This involves complex aggregation processes, creating derived records (RCVs and VCVs) from individual submissions. Frequent incremental updates would create unnecessary confusion and noise; therefore, ClinVar chose weekly batch processing.

In contrast, GenCC submissions are simpler—fewer submitters, less complex aggregation. GenCC might not currently require extensive aggregation (like ClinVar’s RCV/VCV process), making snapshot releases less critical. However, offering occasional stable snapshots (such as monthly or quarterly releases) could still benefit users who prefer referencing stable, historical views of the data.

In summary, adopting ClinVar’s approach to accession management, basic validation, and periodic releases could greatly simplify GenCC’s submission processing. It would also provide clarity for submitters and users, with minimal extra complexity.