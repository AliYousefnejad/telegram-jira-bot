// Every screen and how the header treats it.
// title: i18n key of the header title (none = the TechDesk brand; the task screen shows the task key)
// root: no Back button (the screen starts a new stack)
// language: the header shows the language switch (the profile is out of reach there)
export const SCREENS = {
    home: { root: true },
    'tasks-status': { title: 'title.manageTasks' },
    tasks: { title: 'title.manageTasks' },
    detail: {},
    report: { title: 'title.report' },
    'report-done': { title: 'title.report', root: true },
    timeline: { title: 'title.timeline' },
    'gate-outside': { root: true, language: true },
    'gate-group': { root: true, language: true },
    'gate-denied': { root: true, language: true },
    'link-email': { title: 'title.link', root: true, language: true },
    'link-success': { title: 'title.link', root: true, language: true },
    'token-expired': { title: 'title.link', root: true, language: true },
};
