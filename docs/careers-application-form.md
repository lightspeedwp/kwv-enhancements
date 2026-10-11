# Careers application form — how it works

How a job application travels from the website to HR and the applicant, and what the
"conditional" bits in the confirmation and notifications actually do.

Code: [`modules/careers-application-form.php`](../modules/careers-application-form.php)
(the form), [`modules/careers-application-context.php`](../modules/careers-application-context.php)
(job details), [`modules/email-template.php`](../modules/email-template.php) (branded email).

## One form for every job

There is a single Gravity Form, **"KWV Job Application"**. The plugin creates it on first
load and stores its ID in the `kwv_careers_form_id` option. Every job uses it, both in the
jobs-board modal and on the single-job page. Jobs can't pick a different form.

| Field | ID | Visible? | Filled by |
|---|---|---|---|
| Name | 2 | yes | applicant |
| Surname | 3 | yes | applicant |
| Email | 1 | yes | applicant |
| Phone | 10 | yes | applicant |
| CV (pdf/doc/docx, 5 MB) | 11 | yes | applicant |
| Job ID | 17 | hidden | the modal script, or `{embed_post:ID}` on the job page |
| Job Title | 15 | hidden | server |
| Job Reference | 16 | hidden | server |
| Job Link | 18 | hidden | server |
| Location | 19 | hidden | server |
| Department | 20 | hidden | server |
| Closing Date | 21 | hidden | server |

### How the job details get in

1. The browser sends only the **Job ID**.
2. On submit, `ApplicationContext` looks that job up and writes its title, reference,
   link, location, department and closing date into the hidden fields. Whatever the
   browser sent for those fields is overwritten, so nobody can fake them.
3. If the job has **closed** (past its closing date or no longer published), the
   submission is refused with "Sorry, applications for this position have closed."
4. If the Job ID isn't a real job, the job fields are saved **empty**. This is the case
   that makes the conditional logic below necessary.

The entry in Gravity Forms → Entries then holds both the applicant's answers and the
job they applied for.

## What gets sent

### 1. On-screen confirmation

This replaces the form after a successful submit (inside the modal too):

> Thank you, *Name*. Your application **for *Job Title* (Ref *Job Reference*)** has been
> received, and a copy is on its way to *Email*. …

### 2. "HR — New Application" (email to HR)

- **To:** the `kwv_careers_hr_email` option, falling back to the site admin email.
  ⚠️ KWV's real HR address hasn't been supplied yet. Set it before launch.
- **Reply-To:** the applicant, so HR can just hit reply.
- **Format:** plain text, **CV attached**.
- **Lists:** the position (title, ref, department, location, closing date, link), the
  applicant (name, email, phone, CV link), and the entry ID with a link to it in the admin.

### 3. "Applicant Confirmation" (email to the applicant)

- **To:** the Email field. **Reply-To:** HR.
- **Format:** branded KWV HTML email: a thank-you, a summary table of the application,
  the "one month after closing" line, and a "View current vacancies" button.

Neither notification has Gravity Forms **notification-level** conditional logic
(`conditionalLogic` is empty). Both are always sent on every successful submission.

## The conditional logic, explained

The conditional parts are **inside the message text**, not on the notifications. They
use Gravity Forms' conditional shortcode:

```
[gravityforms action="conditional" merge_tag="{Job Reference:16}" condition="isnot" value=""]
  …only shown when the Job Reference is not empty…
[/gravityforms]
```

In plain English: *"show this bit only if that field has a value."* Its only job is to
**hide blank labels** when a detail is missing, for example a job with no reference
number, or the rare submission that arrives without a valid job (see step 4 above).

| Where | What's wrapped | Without it you'd see |
|---|---|---|
| On-screen confirmation | " for **Job Title**" | "Your application for **** has been received" |
| On-screen confirmation | " (Ref *Job Reference*)" | "… (Ref ) has been received" |
| Applicant email, summary table | The **Position, Reference, Department, Location, Closing date** rows | Empty labelled rows, e.g. "REFERENCE" with nothing beside it |

The applicant's own rows (Name, Email, Phone, CV, Submitted) are **not** wrapped, because
they're always filled in (all required).

### Two related tidy-ups that aren't shortcodes

Gravity Forms doesn't run shortcodes in email subjects or in the plain-text HR email,
so `ApplicationContext::tidy_hr_email()` handles those in code, for the HR email only:

- **Subject:** "Job application: *Title* (Ref *Ref*) — *Name*". When there's no
  reference, it strips the empty "(Ref )". When there's no job details at all, it
  also tidies the dangling ": —" to "Job application — *Name*".
- **Body:** turns the `&#038;` that Gravity Forms puts into the entry link back into `&`,
  so the link works in plain text.

In the HR email body itself, an empty detail just shows as a blank value after its
label (e.g. `Reference:      `). That's intentional: HR sees that the detail was missing.

## Editing the form

You can edit the form in **Forms → KWV Job Application** as normal, but be aware:

- The form is **versioned** (`ApplicationForm::SCHEMA_VERSION`, recorded in the
  `kwv_careers_form_schema` option). When a developer bumps the version, the plugin
  **rewrites the fields, the default confirmation and the two notifications above**,
  overwriting any admin edits to them. Other notifications you add are left alone.
- To change wording permanently, change it in the code and bump the version, so every
  environment ends up the same.
- Don't rename or delete the hidden fields, or change their **Parameter Name**
  (`job_id`, `job_title`, `job_ref`, `job_url`, `job_location`, `job_department`,
  `job_closing`). That name is how the plugin finds them.
- Styling and scripts key off the form's CSS class `kwv-career-form`, never the form
  ID (it differs per environment).
