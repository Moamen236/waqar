import Swal from 'sweetalert2';

/**
 * One shared confirm-dialog helper (SweetAlert2, Section 23's UI library
 * list) rather than every page instantiating its own config — used
 * before every status-changing action a Checking/Delivery/Accounting
 * employee takes (confirm, cancel, mark delivered, etc.).
 */
export async function confirmAction(options: {
    title: string;
    text?: string;
    confirmText?: string;
    danger?: boolean;
}): Promise<boolean> {
    const result = await Swal.fire({
        title: options.title,
        text: options.text,
        icon: options.danger ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: options.confirmText ?? 'Confirm',
        confirmButtonColor: options.danger ? '#dc3545' : '#0d6efd',
        cancelButtonText: 'Cancel',
    });

    return result.isConfirmed;
}

export function notifySuccess(text: string): void {
    void Swal.fire({ icon: 'success', title: 'Done', text, timer: 2000, showConfirmButton: false });
}

export function notifyError(text: string): void {
    void Swal.fire({ icon: 'error', title: 'Something went wrong', text });
}
