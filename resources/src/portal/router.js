import { createRouter, createWebHistory } from 'vue-router';

const router = createRouter({
  history: createWebHistory('/portal'),
  routes: [
    { path: '/login', name: 'PortalLogin', component: () => import('./views/Login.vue'), meta: { guest: true } },
    { path: '/set-password', name: 'PortalSetPassword', component: () => import('./views/SetPassword.vue'), meta: { guest: true } },
    {
      path: '/',
      component: () => import('./components/AuthGate.vue'),
      meta: { requiresAuth: true },
      children: [
        {
          path: '',
          component: () => import('./layouts/PortalLayout.vue'),
          redirect: 'dashboard',
          children: [
            { path: 'dashboard', name: 'PortalDashboard', component: () => import('./views/Dashboard.vue') },
            { path: 'invoices', name: 'PortalInvoices', component: () => import('./views/Invoices.vue') },
            { path: 'invoices/:id', name: 'PortalInvoiceDetail', component: () => import('./views/InvoiceDetail.vue') },
            { path: 'payments', name: 'PortalPayments', component: () => import('./views/Payments.vue') },
            { path: 'statement', name: 'PortalStatement', component: () => import('./views/Statement.vue') },
            { path: 'quotations', name: 'PortalQuotations', component: () => import('./views/Quotations.vue') },
            { path: 'quotations/new', name: 'PortalQuotationRequest', component: () => import('./views/QuotationRequest.vue') },
            { path: 'quotations/:id', name: 'PortalQuotationDetail', component: () => import('./views/QuotationDetail.vue') },
            { path: 'appointments', name: 'PortalAppointments', component: () => import('./views/Appointments.vue') },
            { path: 'appointments/new', name: 'PortalAppointmentBook', component: () => import('./views/AppointmentBook.vue') },
            { path: 'appointments/:id', name: 'PortalAppointmentDetail', component: () => import('./views/AppointmentDetail.vue') },
            { path: 'contracts', name: 'PortalContracts', component: () => import('./views/Contracts.vue') },
            { path: 'contracts/:id', name: 'PortalContractDetail', component: () => import('./views/ContractDetail.vue') },
            { path: 'help', name: 'PortalKnowledgeBase', component: () => import('./views/KnowledgeBase.vue') },
            { path: 'help/:slug', name: 'PortalArticle', component: () => import('./views/Article.vue') },
            { path: 'profile', name: 'PortalProfile', component: () => import('./views/Profile.vue') },
          ],
        },
      ],
    },
  ],
});

// Guard sin efecto (conservado tal cual): la autenticación del portal la resuelve el servidor (routes/tenant_web.php).
router.beforeEach(() => {});

export default router;
