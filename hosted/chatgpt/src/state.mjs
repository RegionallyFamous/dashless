export const phases = [
  ['saved', 'Draft saved'], ['preview_ready', 'Preview ready'],
  ['published_in_wordpress', 'Changes saved for publishing'], ['deployed', 'Blog updated'], ['publicly_verified', 'Live blog checked'],
];
export function describe(data = {}) {
  if (data.publicly_verified === true && data.status === 'succeeded') return ['Your blog is live', 'Your approved changes are now online.'];
  if (data.status === 'failed') return ['We couldn’t finish your changes', data.previous_release_preserved === true ? 'Your previous version is still online. Ask Dashless to help you finish these changes.' : 'Ask Dashless to check your blog and help you try again.'];
  if (data.status === 'canceled') return ['Changes stopped', 'These changes weren’t completed. Ask Dashless to check your blog before trying again.'];
  if (data.status === 'running') return ['Preparing your blog', 'Work is running in the background. You can keep writing here.'];
  if (data.status === 'queued') return ['Your changes are next', 'Your changes are waiting to be prepared. We haven’t confirmed they’re live yet.'];
  if (data.deployed === true) return ['Checking your live blog', 'Your blog was updated. We’re checking that your changes are visible online.'];
  if (data.published_in_wordpress === true) return ['Getting your changes online', 'Your approved changes are saved. We haven’t confirmed they’re live yet.'];
  if (data.preview_ready === true || data.preview_id) return ['Ready for your review', 'Open your private preview in Dashless. Review the page and confirm Publish there.'];
  if (data.approval_required && data.release_id) return ['Restore an earlier version', 'Open Dashless to review the earlier version before restoring it.'];
  if (data.status === 'succeeded') return ['Your changes are ready', 'Your changes are prepared. We haven’t confirmed they’re live yet.'];
  return ['Your words. Your call.', 'Create a preview in your conversation to review your blog and follow its progress here.'];
}
export function reviewUrl(value) {
  try { const url = new URL(value); return url.origin === 'https://dashless.blog' && url.pathname === '/preview/' && !url.username && !url.password ? url.href : null; } catch { return null; }
}
export function nextDelay(attempt) { return Math.min(30000, 2000 * 2 ** Math.min(attempt, 4)); }
