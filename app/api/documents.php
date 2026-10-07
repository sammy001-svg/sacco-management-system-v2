<?php
defined('SACCO') or exit;

const DOC_TYPES = [
    'photo'      => 'Passport photo',
    'id_front'   => 'National ID — front',
    'id_back'    => 'National ID — back',
    'signature'  => 'Signature specimen',
    'tax_pin'    => 'Tax PIN certificate',
    'payslip'    => 'Payslip',
    'other'      => 'Other document',
];
const DOC_KYC = ['photo', 'id_front', 'id_back', 'signature'];
const DOC_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

function doc_dir(int $memberId): string
{
    $dir = APP_ROOT . '/storage/uploads/members/' . $memberId;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function doc_scoped(int $id): array
{
    $d = DB::row('SELECT d.*, m.branch_id FROM member_documents d JOIN members m ON m.id = d.member_id WHERE d.id = ?', [$id]);
    if (!$d || !MemberService::inScope($d)) {
        throw new AppError('Document not found.', 404);
    }
    return $d;
}

return [

    'list' => function () {
        Auth::authorize('members.view');
        $m = MemberService::findOrFail(input_int('member_id'));
        $docs = DB::all('SELECT d.id, d.type, d.original_name, d.mime, d.size, d.created_at, u.name AS uploaded_by_name FROM member_documents d LEFT JOIN users u ON u.id = d.uploaded_by WHERE d.member_id = ? ORDER BY d.id DESC', [$m['id']]);
        $have = array_unique(array_column($docs, 'type'));
        return ['data' => $docs, 'types' => DOC_TYPES, 'kyc' => [
            'required' => DOC_KYC,
            'missing'  => array_values(array_diff(DOC_KYC, $have)),
            'complete' => !array_diff(DOC_KYC, $have),
        ]];
    },

    'upload' => function () {
        post_only();
        Auth::authorize('members.manage');
        $m = MemberService::findOrFail(input_int('member_id'));
        $type = (string) input('type');
        if (!isset(DOC_TYPES[$type])) {
            throw new ValidationError(['type' => 'Choose the document type.']);
        }
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ValidationError(['file' => 'Choose a file to upload.']);
        }
        if ($f['size'] > 5 * 1024 * 1024) {
            throw new ValidationError(['file' => 'Files must be 5 MB or smaller.']);
        }
        // Trust the file's content, not its name or the browser's claim.
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!isset(DOC_MIME[$mime])) {
            throw new ValidationError(['file' => 'Only JPG, PNG, WEBP images and PDF files are accepted.']);
        }
        if (in_array($type, ['photo', 'signature'], true) && $mime === 'application/pdf') {
            throw new ValidationError(['file' => 'Photos and signatures must be images.']);
        }
        $stored = bin2hex(random_bytes(16)) . '.' . DOC_MIME[$mime];
        if (!move_uploaded_file($f['tmp_name'], doc_dir((int) $m['id']) . '/' . $stored)) {
            throw new AppError('Could not save the file. Check that storage/uploads is writable.', 500);
        }
        $id = DB::transaction(function () use ($m, $type, $f, $stored, $mime) {
            if ($type === 'photo') {
                // Only one current photo.
                foreach (DB::all("SELECT id, stored_name FROM member_documents WHERE member_id = ? AND type = 'photo'", [$m['id']]) as $old) {
                    @unlink(doc_dir((int) $m['id']) . '/' . $old['stored_name']);
                    DB::query('DELETE FROM member_documents WHERE id = ?', [$old['id']]);
                }
            }
            return DB::insert('member_documents', [
                'member_id' => $m['id'], 'type' => $type, 'original_name' => mb_substr(basename((string) $f['name']), 0, 190),
                'stored_name' => $stored, 'mime' => $mime, 'size' => (int) $f['size'], 'uploaded_by' => Auth::id(),
            ]);
        });
        audit('upload', 'member_document', $id, DOC_TYPES[$type] . " uploaded for {$m['member_no']}");
        return ['message' => DOC_TYPES[$type] . ' uploaded.', 'id' => $id];
    },

    /** Streams a document to signed-in users within scope (files are never web-accessible). */
    'view' => function () {
        Auth::authorize('members.view');
        $d = doc_scoped(input_int('id'));
        $path = doc_dir((int) $d['member_id']) . '/' . $d['stored_name'];
        if (!is_file($path)) {
            throw new AppError('The file is missing from storage.', 404);
        }
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: ' . $d['mime']);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . (input('download') ? 'attachment' : 'inline') . '; filename="' . str_replace('"', '', $d['original_name']) . '"');
        header('Cache-Control: private, max-age=600');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        readfile($path);
        exit;
    },

    'delete' => function () {
        post_only();
        Auth::authorize('members.manage');
        $d = doc_scoped(input_int('id'));
        @unlink(doc_dir((int) $d['member_id']) . '/' . $d['stored_name']);
        DB::query('DELETE FROM member_documents WHERE id = ?', [$d['id']]);
        audit('delete', 'member_document', $d['id'], 'Deleted ' . (DOC_TYPES[$d['type']] ?? $d['type']) . " of member #{$d['member_id']}");
        return ['message' => 'Document deleted.'];
    },
];
