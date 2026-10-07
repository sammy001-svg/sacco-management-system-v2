<?php
defined('SACCO') or exit;

function import_perm(string $kind): string
{
    return $kind === 'members' ? 'imports.members' : 'imports.checkoff';
}

function import_scoped(int $id): array
{
    $imp = DB::row('SELECT i.*, u.name AS user_name FROM imports i LEFT JOIN users u ON u.id = i.created_by WHERE i.id = ?', [$id]);
    if (!$imp) {
        throw new AppError('Import not found.', 404);
    }
    Auth::authorize(import_perm($imp['kind']));
    $imp['meta'] = json_decode((string) $imp['meta'], true) ?: [];
    return $imp;
}

function uploaded_csv(): array
{
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new ValidationError(['file' => 'Choose a CSV file to upload.']);
    }
    if (!preg_match('/\.(csv|txt)$/i', (string) $f['name'])) {
        throw new ValidationError(['file' => 'Upload a .csv file (save Excel sheets as “CSV UTF-8”).']);
    }
    if ($f['size'] > 15 * 1024 * 1024) {
        throw new ValidationError(['file' => 'The file is larger than 15 MB. Split it into smaller files.']);
    }
    return $f;
}

return [

    'list' => function () {
        $kinds = array_values(array_filter(['members', 'checkoff'], fn($k) => Auth::can(import_perm($k))));
        if (!$kinds) {
            throw new AppError('You do not have permission to perform this action.', 403);
        }
        $in = implode(',', array_fill(0, count($kinds), '?'));
        return paginate(
            'i.*, u.name AS user_name',
            "FROM imports i LEFT JOIN users u ON u.id = i.created_by WHERE i.kind IN ($in)",
            $kinds, 'i.id DESC', 15, "FROM imports i WHERE i.kind IN ($in)"
        );
    },

    'template' => function () {
        $kind = input('kind') === 'members' ? 'members' : 'checkoff';
        Auth::authorize(import_perm($kind));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . ($kind === 'members' ? 'member-import' : 'payroll-checkoff') . '-template.csv"');
        echo ImportService::template($kind);
        exit;
    },

    'upload_members' => function () {
        post_only();
        Auth::authorize('imports.members');
        $f = uploaded_csv();
        set_time_limit(300);
        $id = ImportService::stageMembers($f['tmp_name'], $f['name']);
        audit('import_upload', 'import', $id, "Uploaded member file {$f['name']}");
        return ['message' => 'File validated. Review the rows, then import.', 'id' => $id];
    },

    'upload_checkoff' => function () {
        post_only();
        Auth::authorize('imports.checkoff');
        $meta = Validator::make($_POST, [
            'source'          => 'required|string|max:120',
            'period'          => 'required|string|max:20',
            'payment_ref'     => 'nullable|string|max:80',
            'amount_received' => 'nullable|numeric|min:0',
            'posting_date'    => 'required|date',
        ], ['source' => 'Employer', 'amount_received' => 'Amount received']);
        if ($meta['posting_date'] > today()) {
            throw new ValidationError(['posting_date' => 'Posting date cannot be in the future.']);
        }
        Accounting::assertOpen($meta['posting_date']);
        $f = uploaded_csv();
        set_time_limit(300);
        $id = ImportService::stageCheckoff($f['tmp_name'], $f['name'], $meta);
        audit('import_upload', 'import', $id, "Uploaded check-off {$meta['source']} {$meta['period']}");
        return ['message' => 'Check-off file validated. Review the rows, then post.', 'id' => $id];
    },

    'get' => function () {
        return ['data' => import_scoped(input_int('id'))];
    },

    'rows' => function () {
        $imp = import_scoped(input_int('id'));
        $where = 'WHERE import_id = ?';
        $params = [$imp['id']];
        if (in_array($st = (string) input('status', ''), ['valid', 'error', 'done', 'failed'], true)) {
            $where .= ' AND status = ?';
            $params[] = $st;
        }
        $res = paginate('*', "FROM import_rows $where", $params, 'id', 20);
        foreach ($res['data'] as &$r) {
            $r['data'] = json_decode($r['data'], true);
        }
        return $res;
    },

    'process' => function () {
        post_only();
        $imp = import_scoped(input_int('id'));
        set_time_limit(120);
        $r = ImportService::process((int) $imp['id']);
        if ($r['status'] === 'completed') {
            audit('import_complete', 'import', $imp['id'], "Completed {$imp['kind']} import {$imp['filename']}: {$r['processed_rows']} rows, {$r['failed_rows']} failed");
        }
        return ['data' => $r];
    },

    'cancel' => function () {
        post_only();
        $imp = import_scoped(input_int('id'));
        if ($imp['status'] !== 'validated') {
            throw new AppError('Only imports that have not started can be cancelled.');
        }
        DB::update('imports', ['status' => 'cancelled'], 'id = ?', [$imp['id']]);
        return ['message' => 'Import cancelled. Nothing was posted.'];
    },
];
