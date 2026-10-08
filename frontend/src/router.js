// Navigation is a stack kept in the nav store (Telegram's Back button pops it); the router shows its top screen.
// Memory history: Telegram owns the URL (it puts the launch data in the hash).
import { createMemoryHistory, createRouter } from 'vue-router';
import { SCREENS } from '@/screens.js';
import HomeView from '@/views/HomeView.vue';
import StatusMenuView from '@/views/StatusMenuView.vue';
import TaskListView from '@/views/TaskListView.vue';
import TaskDetailView from '@/views/TaskDetailView.vue';
import ReportView from '@/views/ReportView.vue';
import ReportDoneView from '@/views/ReportDoneView.vue';
import TimelineView from '@/views/TimelineView.vue';
import GateOutsideView from '@/views/GateOutsideView.vue';
import GateGroupView from '@/views/GateGroupView.vue';
import GateDeniedView from '@/views/GateDeniedView.vue';
import TokenExpiredView from '@/views/TokenExpiredView.vue';
import LinkEmailView from '@/views/LinkEmailView.vue';
import LinkSuccessView from '@/views/LinkSuccessView.vue';

const COMPONENTS = {
    home: HomeView,
    'tasks-status': StatusMenuView,
    tasks: TaskListView,
    detail: TaskDetailView,
    report: ReportView,
    'report-done': ReportDoneView,
    timeline: TimelineView,
    'gate-outside': GateOutsideView,
    'gate-group': GateGroupView,
    'gate-denied': GateDeniedView,
    'link-email': LinkEmailView,
    'link-success': LinkSuccessView,
    'token-expired': TokenExpiredView,
};

export const router = createRouter({
    history: createMemoryHistory(),
    routes: Object.entries(SCREENS).map(([name, meta]) => ({
        path: name === 'home' ? '/' : '/' + name,
        name,
        component: COMPONENTS[name],
        meta,
    })),
});
