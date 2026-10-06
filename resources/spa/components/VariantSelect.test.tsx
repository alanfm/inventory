import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, expect, test } from "vitest";
import { VariantSelect } from "./VariantSelect";

afterEach(cleanup);

const options = [
  { value: "1", label: "TC001 — Teclado / Logitech USB" },
  { value: "2", label: "MEM01 — Memória / Kingston DDR4" },
];

function setup() {
  const user = userEvent.setup();
  const { container } = render(
    <form>
      <label htmlFor="variantId">Variante</label>
      <VariantSelect options={options} required />
    </form>,
  );
  return {
    user,
    input: screen.getByRole("combobox", { name: "Variante" }),
    form: container.querySelector("form")!,
  };
}

test("searches without accents and submits the selected variant ID", async () => {
  const { user, input, form } = setup();
  await user.type(input, "memoria");
  expect(screen.getAllByRole("option")).toHaveLength(1);
  await user.click(screen.getByRole("option", { name: options[1].label }));
  expect(input).toHaveValue(options[1].label);
  expect(new FormData(form).get("variantId")).toBe("2");
  expect(form.checkValidity()).toBe(true);
});

test("supports arrow keys, Enter and Escape without propagating to the modal", async () => {
  const { user, input, form } = setup();
  await user.click(input);
  await user.keyboard("{ArrowDown}{Enter}");
  expect(new FormData(form).get("variantId")).toBe("2");
  await user.tab();
  await user.click(input);
  await user.keyboard("{Escape}");
  expect(input).toHaveAttribute("aria-expanded", "false");
  expect(input).toHaveFocus();
});

test("shows an empty result and prevents submitting free text as a variant", async () => {
  const { user, input, form } = setup();
  await user.type(input, "inexistente");
  expect(screen.getByRole("status")).toHaveTextContent(
    "Nenhuma variante encontrada.",
  );
  expect(form.checkValidity()).toBe(false);
  expect(new FormData(form).get("variantId")).toBe("");
  await user.clear(input);
  await user.type(input, "Logitech");
  await user.keyboard("{Enter}");
  expect(form.checkValidity()).toBe(true);
  await user.type(input, " outro");
  expect(form.checkValidity()).toBe(false);
  expect(new FormData(form).get("variantId")).toBe("");
});
