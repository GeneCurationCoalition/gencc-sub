# status processing clean up

I want to assure that all code and tests and data tables are in sync with the common flow for creating, submitting and releasing jobs and submissions.

## Submissions

submission records now will always be associated with one and only one job id. before they were not. when and if a submission is republished or unpublished it will be copied to a new job and given the same sgc id with an incremented version number.  the 3 generalized state transitions are "created", "submitted" and "released". we want to capture the timestamp for these 3 states. there will only ever be one created_at timestamp (when the submission record is created or drafted). there will only ever be one released_at timestamp (when the submission record is released or processed or published or unpublished).  There will only ever be one submitted_at timestamp (when the submission record transitions from draft to submitted).

The general status concepts for each of these 3 dates is created_at -> DRAFT, submitted_at -> SUBMITTED, released_at -> RELEASED. However, we need to be more nuanced about the statuses for the submissions since there are several subtypes in some of these general status concepts. 

When a brand new submissions or the version 1 version of a submission is created this is a NEW submission, but when a previously released submission is replublished and therefore is a copy of a previous submission with an incremented version number then it is a REPUBLISH submission. And finally if the previously released submission is unpublished then it is also copied to a new job with the version number incremented and it is considered in an UNPUBLISH state. So NEW, REPUBLISH and UNPUBLISH are the 3 kinds of DRAFT submissions (i.e. DRAFT_NEW, DRAFT_REPUBLISH and DRAFT_UNPUBLISH).

Since only one job per submitter can be in either a DRAFT or SUBMITTED state we end up having the same 3 substates for SUBMITTED submissions: SUBMITTED_NEW, SUBMITTED_REPUBLISH and SUBMITTED_UNPUBLISH.  

It is also extremely important that only the most recent version for a given sgc id set of submissions is flagged as such. We use the is_current boolen to indicate which version of an SGC id is the most recent. We may want to change that field name to is_most_recent.

The RELEASED general state concept for a submission ends up in one of 2 substates PUBLISHED and UNPUBLISHED. We use these explicity substate terms for the status and do not prefix them with RELEASED. In the end we probably don't need to use the compound STATUS terms for DRAFT_* and SUBMITTED_* since we can determine the NEW and SUBMITTED portion of the status by using the created_at, submitted_at and released_at timestamp fields. We also don't need the NEW status since we can determine that by the version number (version number 1 will always be NEW). We would only need to know when something is being drafted, submitted and released as UNPUBLISHED vs PUBLISHED so we could add a flag to indicate if the submission is_removed where false would be used for NEW, REPUBLISH and ultimately PUBLISHED vs true where it would be used for all UNPUBLISHED.

As new versions of the same SGC id are created the previous versions should be marked as is_most_recent = false (formerly is_current = false). And all ability to edit these historical submissions should be removed. If the most recent version of an SGC id is deleted which is only available for DRAFT submissions, then the previous version would be restored as the most recent version and become republishable or unpublishable again.

## Jobs

Jobs will have the same 3 generalized status concepts DRAFT, SUBMITTED and RELEASED. Jobs will have the same 3 timestamp fields as submissions as well created_at, submitted_at and released_at. Any submissions that are successfully processed during the release process (formerly run publish) for a given job will always be associated with that job (see Release processing below for more details). The display of RELEASED jobs should simply say "RELEASED" (currently they say "PROCESSED").

We should also add an is_most_recent flag to jobs to indicate which job is most recent. the DRAFT or SUBMITTED job would always be the most_recent, otherwise it would be the job with the largest identifier. All other jobs would be considered historical since they are not the most recent.  All job listings by default should be sorted by descending job id so that the most recent is at the top and they are in reverse chronological order.

### Release processing also known as run publish.

Currently, the Run publish command is responsible for going through all submitted jobs and processing their submissions. This means that it loops through each submission for a job and sends a request to the gencc-search application for it to be accessible to the public. If the gencc-search api does not return an error then the submission is successfully released and will be marked as such. If the gencc-search API returns an error then the specific submission is copied to a new DRAFT job and marked with the error so that the submitter can be notified and guided on how to correct it and resubmit in a subsquent RELEASE processing cycle. This means that upon completion of all submissions in a given submitted job, all the successfully processed submissions will be forever associated with that single job. Only if an error occurs during the release processing can a specific submission version be moved from it's current job to a new draft job.

## some general visual standards related to statuses

In general the system should visually represent labels and themes for jobs and submissions in one of 4 color themes green, blue, yellow/amber and gray.  Green is for the most recent released submissions and jobs. Gray is for all historical (not most recent) submissions and jobs. blue is for submitted submissionsn and jobs. And, yellow/amber is for all DRAFT submissions and jobs. This does mean that some historical jobs may have some green most_recent submissions and that is okay.

## field and code clean up

i will want to audit the submission and job tables to assure that all the fields are still in use once we refactor the code to efficiently and effectively fulfill the requirements for the status management above. I will also want an audit of the code to make sure there is no unnecessary duplication of logic related to the status managment above. but we can leave that to after we get all the requirements fulfilled and tested.


# Refactoring Submission Statistics in the dashboard

## Submissions Released

the breakdown of the total number of released submission requests broken down by how many were first version, republish and hidden/unpublish.  The first version and republish counts should sum to the number of submissions that are viewable in the gencc-search application.  The submissions that are considered in this section should only be the live versions of a submission. 

## Submissions Awaiting Release

It should also show the number of submission requests awaiting release broken down by how many are first version, republish and remove/unpublish submission requests.

## Submissions Archived

This will be the number where ther released_at is not null and the is_live is false. And it can be broken down by first version, republish and unpublish. it should only consider the max version for any given sgc id so that we don't double count archiving a single sgc id that has had several released republishings or unpublishings.  If the max version is unpublished then it should count towards an Unpublished archived submission, otherwise it should count as a first version if it is version 1 and finally all others should be republished submissions.
Since archived submissions can have multiple versions for the same sgc id, we should provide a separate number (in parentheses) for each count that is the total for the given breakdown category. Only Republish and Unpublish would potentially have differing counts of unique vs all... So, First Version: 100, Republish: 200 (240), Hidden/Unpublish: 10 (12) as an example of how it might be presented.