import fs from 'node:fs';
import path from 'node:path';

const detailedPath = path.resolve(process.argv[2] ?? 'storage/app/marketing-reports/zoho-lead-by-lead-audit-2026-08-12.html');
const summaryPath = path.resolve(process.argv[3] ?? 'storage/app/marketing-reports/zoho-lead-marketing-strategy-report-2026-08-12.html');

function embeddedJson(filePath, elementId) {
    const html = fs.readFileSync(filePath, 'utf8');
    const marker = `<script type="application/json" id="${elementId}">`;
    const start = html.indexOf(marker);
    if (start < 0) {
        throw new Error(`${elementId} is missing from ${filePath}`);
    }

    const jsonStart = start + marker.length;
    const end = html.indexOf('</script>', jsonStart);
    if (end < 0) {
        throw new Error(`${elementId} is not closed in ${filePath}`);
    }

    return JSON.parse(html.slice(jsonStart, end));
}

function assert(condition, message) {
    if (! condition) {
        throw new Error(message);
    }
}

const detailed = embeddedJson(detailedPath, 'reportData');
const summary = embeddedJson(summaryPath, 'summaryData');
const ids = detailed.leads.map((lead) => String(lead.zoho_id));
const categoryTotal = Object.values(detailed.category_counts).reduce((sum, count) => sum + Number(count), 0);
const directActivityLeads = Number(detailed.source_summary.coverage.leads_with_direct_activity);
const invalidVerificationStatuses = new Set(['invalid', 'undeliverable', 'disposable']);
const invalidVerificationLeads = detailed.leads.filter((lead) => invalidVerificationStatuses.has(lead.email_verification_status));
const invalidVerificationNotSuppressed = invalidVerificationLeads.filter((lead) => lead.category !== 'hard_suppression');
const staleTaskCutoff = new Date(detailed.meta.generated_at);
staleTaskCutoff.setDate(staleTaskCutoff.getDate() - 30);
const recentQuoteCutoff = new Date(detailed.meta.generated_at);
recentQuoteCutoff.setDate(recentQuoteCutoff.getDate() - 45);
const staleOpenTaskLeads = detailed.leads.filter((lead) => lead.open_task_due && new Date(lead.open_task_due) < staleTaskCutoff);
const humanFollowUpWithStaleTask = staleOpenTaskLeads.filter((lead) => lead.category === 'human_follow_up');
const staleTaskOnlyHumanFollowUp = humanFollowUpWithStaleTask.filter((lead) => {
    const quoteLine = lead.why.find((reason) => reason.startsWith('Linked quote context exists; latest dated '));
    const quoteDate = quoteLine?.match(/(\d{4}-\d{2}-\d{2})/)?.[1];
    const hasRecentQuote = quoteDate ? new Date(`${quoteDate}T00:00:00Z`) >= recentQuoteCutoff : false;
    return ! lead.has_reply && ! lead.has_demande && ! hasRecentQuote && (lead.recency_days === null || lead.recency_days > 7);
});
const staleConvertedActive = detailed.leads.filter((lead) => lead.category === 'converted_active'
    && (lead.recency_days === null || lead.recency_days > 180));

assert(ids.length === detailed.meta.lead_count, 'Detailed report lead count does not match its metadata.');
assert(new Set(ids).size === ids.length, 'Detailed report contains duplicate Zoho Lead IDs.');
assert(categoryTotal === ids.length, 'Category totals do not cover every Lead exactly once.');
assert(directActivityLeads >= 2_900, `Expected at least 2,900 Leads with linked direct CRM activity; found ${directActivityLeads}.`);
assert(invalidVerificationNotSuppressed.length === 0, `${invalidVerificationNotSuppressed.length} invalid or undeliverable email(s) escaped hard suppression.`);
assert(staleTaskOnlyHumanFollowUp.length === 0, `${staleTaskOnlyHumanFollowUp.length} Leads were marked for immediate follow-up only because of a task overdue by more than 30 days.`);
assert(staleConvertedActive.length === 0, `${staleConvertedActive.length} converted Leads were called active despite no evidence within 180 days.`);
assert(summary.meta.lead_count === detailed.meta.lead_count, 'Summary and detailed report lead counts differ.');
assert(summary.top_targets.length === 50, 'Summary priority queue must contain 50 Leads.');
assert(summary.strategies.length === detailed.categories.length, 'Every category must have one strategy.');

console.log(JSON.stringify({
    detailed: detailedPath,
    summary: summaryPath,
    leads: ids.length,
    unique_ids: new Set(ids).size,
    direct_activity_leads: directActivityLeads,
    invalid_verification_leads: invalidVerificationLeads.length,
    stale_open_task_leads: staleOpenTaskLeads.length,
    human_follow_up_with_stale_task: humanFollowUpWithStaleTask.length,
    stale_task_only_human_follow_up: staleTaskOnlyHumanFollowUp.length,
    stale_converted_active: staleConvertedActive.length,
    timeline_events: detailed.meta.timeline_event_count,
    categories: detailed.category_counts,
}, null, 2));
