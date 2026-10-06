import type { FrontendModule } from "@starterkit/module-kit";

const module: FrontendModule = {
  name: "inventory",
  displayName: "Almoxarifado",
  navigationGroup: { icon: "Boxes", order: 60 },
  routes: [
    {
      path: "admin/inventory",
      load: () => import("./pages/ItemsPage"),
      permission: "inventory.items.viewAny",
    },
    {
      path: "admin/inventory/new",
      load: () => import("./pages/ItemCreatePage"),
      permission: "inventory.items.create",
    },
    {
      path: "admin/inventory/dashboard",
      load: () => import("./pages/DashboardPage"),
      permission: "inventory.dashboard.view",
    },
    {
      path: "admin/inventory/reports",
      load: () => import("./pages/ReportsPage"),
      permission: "inventory.reports.view",
    },
    {
      path: "admin/inventory/imports",
      load: () => import("./pages/ImportsPage"),
      permission: "inventory.imports.view",
    },
    {
      path: "admin/inventory/categories",
      load: () => import("./pages/CategoriesPage"),
      permission: "inventory.categories.viewAny",
    },
    {
      path: "admin/inventory/:id",
      load: () => import("./pages/ItemDetailPage"),
      permission: "inventory.items.view",
    },
    {
      path: "admin/inventory/movements",
      load: () => import("./pages/MovementsPage"),
      permission: "inventory.movements.viewAny",
    },
    {
      path: "admin/inventory/movements/entry",
      load: () =>
        import("./pages/MovementCreatePage").then((page) => ({
          default: page.EntryCreatePage,
        })),
      permission: "inventory.entries.create",
    },
    {
      path: "admin/inventory/movements/issue",
      load: () =>
        import("./pages/MovementCreatePage").then((page) => ({
          default: page.IssueCreatePage,
        })),
      permission: "inventory.issues.create",
    },
    {
      path: "admin/inventory/movements/:id",
      load: () => import("./pages/MovementDetailPage"),
      permission: "inventory.movements.view",
    },
    {
      path: "admin/inventory/adjustments/new",
      load: () => import("./pages/AdjustmentCreatePage"),
      permission: "inventory.adjustments.create",
    },
  ],
  navigation: [
    {
      to: "/admin/inventory/dashboard",
      label: "Painel",
      icon: "FileText",
      permission: "inventory.dashboard.view",
      order: 60,
    },
    {
      to: "/admin/inventory/movements",
      label: "Movimentações",
      icon: "ArrowLeftRight",
      permission: "inventory.movements.viewAny",
      order: 61,
    },
    {
      to: "/admin/inventory",
      label: "Itens",
      icon: "Boxes",
      permission: "inventory.items.viewAny",
      order: 62,
      end: true,
    },
    {
      to: "/admin/inventory/categories",
      label: "Categorias",
      icon: "Tags",
      permission: "inventory.categories.viewAny",
      order: 63,
    },
    {
      to: "/admin/inventory/reports",
      label: "Relatórios",
      icon: "FileText",
      permission: "inventory.reports.view",
      order: 65,
    },
    {
      to: "/admin/inventory/imports",
      label: "Importações",
      icon: "FileText",
      permission: "inventory.imports.view",
      order: 66,
    },
  ],
};
export default module;
