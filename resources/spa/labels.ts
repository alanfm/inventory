export const unitLabels: Record<string, string> = {
  UN: "Unidade",
  PAR: "Par",
  CX: "Caixa",
};

export const movementTypeLabels: Record<string, string> = {
  ENTRY: "Entrada",
  ISSUE: "Saída",
  ADJUSTMENT: "Ajuste",
  REVERSAL: "Estorno",
};

export const movementStatusLabels: Record<string, string> = {
  DRAFT: "Rascunho",
  POSTED: "Confirmado",
  CANCELLED: "Descartado",
  REVERSED: "Estornado",
};

export const situationLabels: Record<string, string> = {
  INCONSISTENT: "Inconsistente",
  OUT_OF_STOCK: "Sem estoque",
  NOT_CONFIGURED: "Não configurado",
  REPLENISHMENT: "Repor",
  OK: "Regular",
};

export function labeled(labels: Record<string, string>, value: string): string {
  return labels[value] ?? value;
}
