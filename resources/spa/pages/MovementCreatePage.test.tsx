import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import type { ReactNode } from "react";
import { MemoryRouter } from "react-router";
import { afterEach, expect, test, vi } from "vitest";
import { useAsync } from "../support";
import { movementService } from "../services/movementService";
import { EntryCreatePage, IssueCreatePage } from "./MovementCreatePage";

vi.mock("../support", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../support")>()),
  useAsync: vi.fn(),
  FormModal: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock("./MovementsPage", () => ({ default: () => null }));
vi.mock("../services/movementService", () => ({
  movementService: { createDraft: vi.fn() },
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

test.each([
  { type: "ENTRY", Page: EntryCreatePage, balances: [] },
  {
    type: "ISSUE",
    Page: IssueCreatePage,
    balances: [{ locationId: "99", quantity: 5, version: 1 }],
  },
])(
  "submits $type without deriving its location from balances",
  async ({ type, Page, balances }) => {
    vi.mocked(useAsync).mockReturnValue({
      data: {
        data: [
          {
            id: "1",
            code: "TC001",
            name: "Teclado",
            variants: [
              {
                id: "2",
                active: true,
                brand: "Logitech",
                description: "USB",
                balances,
              },
            ],
          },
        ],
      },
      loading: false,
      error: null,
      reload: vi.fn(),
    });
    vi.mocked(movementService.createDraft).mockResolvedValue({
      id: 7,
    } as Awaited<ReturnType<typeof movementService.createDraft>>);
    const actor = userEvent.setup();
    render(
      <MemoryRouter>
        <Page />
      </MemoryRouter>,
    );

    await actor.click(screen.getByRole("combobox", { name: /Variante/ }));
    await actor.click(screen.getByRole("option", { name: /TC001/ }));
    await actor.type(screen.getByLabelText(/Quantidade/), "3");
    await actor.click(screen.getByRole("button", { name: "Salvar rascunho" }));

    await waitFor(() =>
      expect(movementService.createDraft).toHaveBeenCalledOnce(),
    );
    const input = vi.mocked(movementService.createDraft).mock.calls[0]?.[0];
    expect(input).toMatchObject({
      type,
      lines: [{ variantId: "2", quantity: 3 }],
    });
    expect(input).not.toHaveProperty("locationId");
    expect(screen.queryByText(/Variante sem local/)).not.toBeInTheDocument();
  },
);
