import type { ReactNode } from "react";
import { useNavigate } from "react-router";
import { can } from "./permissions";
import { useSession } from "@starterkit/module-kit";
import { Button } from "@starterkit/module-kit";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "./Dialog";

interface FormModalProps {
  title: string;
  description?: string;
  returnTo: string;
  background?: ReactNode;
  backgroundPermission?: string;
  children: ReactNode;
}

/** Route-backed form: direct links and browser Back remain available. */
export function FormModal({
  title,
  description,
  returnTo,
  background,
  backgroundPermission,
  children,
}: FormModalProps) {
  const navigate = useNavigate();
  const { state } = useSession();
  const close = () => navigate(returnTo, { replace: true });

  return (
    <>
      {!backgroundPermission || can(state.user, backgroundPermission)
        ? background
        : null}
      <Dialog
        open
        onOpenChange={(open) => {
          if (!open) close();
        }}
      >
        <DialogContent
          className="max-w-4xl"
          onPointerDownOutside={(event) => event.preventDefault()}
        >
          <DialogHeader>
            <DialogTitle>{title}</DialogTitle>
            <DialogDescription>
              {description ?? "Preencha os campos e salve para concluir."}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-6">{children}</div>
          <div className="mt-6 flex justify-end">
            <Button type="button" variant="secondary" onClick={close}>
              Fechar
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  );
}
