<?php
// public/shared.php
require_once 'config/database.php';

$token = $_GET['t'] ?? '';
$type = $_GET['type'] ?? '';

if (empty($token) || empty($type)) {
    die("Invalid or missing share link.");
}

$db = new Database();
$pdo = $db->getConnection();
$data = null;

try {
    if ($type === 'note') {
        $stmt = $pdo->prepare("SELECT title, content, created_at, category, NULL as status FROM notes WHERE share_token = :t");
        $stmt->execute([':t' => $token]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($type === 'kb') {
        $stmt = $pdo->prepare("SELECT title, category, content, updated_at as created_at, NULL as status FROM kb_articles WHERE share_token = :t");
        $stmt->execute([':t' => $token]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($type === 'task') {
        $stmt = $pdo->prepare("SELECT title, description as content, task_category as category, status, due_date as created_at FROM tasks WHERE share_token = :t");
        $stmt->execute([':t' => $token]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($type === 'meeting') {
        $stmt = $pdo->prepare("SELECT title, notes as content, meeting_date as created_at, NULL as category, NULL as status FROM meetings WHERE share_token = :t");
        $stmt->execute([':t' => $token]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($type === 'budget') {
        $stmt = $pdo->prepare("SELECT budget_year, budget_month, budget_data, updated_at as created_at FROM budget_plans WHERE share_token = :t");
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $monthName = date("F", mktime(0, 0, 0, $row['budget_month'], 10));
            $data = [
                'title' => "Budget Plan - {$monthName} {$row['budget_year']}",
                'created_at' => $row['created_at'],
                'category' => 'Financial',
                'status' => ''
            ];
            
            $bData = json_decode($row['budget_data'], true);
            $incTotal = 0; $expTotal = 0;
            
            $incHtml = "<h3 style='border-bottom:1px solid var(--border-color); padding-bottom:5px; color:#22c55e;'>Income Streams</h3><ul style='list-style:none; padding:0;'>";
            if (!empty($bData['income'])) {
                foreach($bData['income'] as $inc) {
                    $amt = (float)($inc['amount'] ?? 0);
                    $incTotal += $amt;
                    $incHtml .= "<li style='display:flex; justify-content:space-between; padding:8px; background:var(--bg-color); margin-bottom:5px; border-radius:6px; border:1px solid var(--border-color);'><span>" . htmlspecialchars($inc['name']) . "</span><strong>$" . number_format($amt, 2) . "</strong></li>";
                }
            }
            $incHtml .= "</ul>";
            
            $expHtml = "<h3 style='border-bottom:1px solid var(--border-color); padding-bottom:5px; color:#ef4444; margin-top:30px;'>Assigned Dollars</h3>";
            if (!empty($bData['expenses'])) {
                $grouped = [];
                foreach($bData['expenses'] as $exp) {
                    $grp = $exp['group'] ?? 'Uncategorized';
                    $grouped[$grp][] = $exp;
                }
                foreach($grouped as $g => $items) {
                    $expHtml .= "<div style='margin-bottom: 15px;'><div style='font-size:0.85em; font-weight:bold; color:var(--text-muted); text-transform:uppercase; margin-bottom:5px;'>" . htmlspecialchars($g) . "</div><ul style='list-style:none; padding:0; margin:0;'>";
                    foreach($items as $i) {
                        $amt = (float)($i['amount'] ?? 0);
                        $expTotal += $amt;
                        $expHtml .= "<li style='display:flex; justify-content:space-between; padding:8px; background:var(--bg-color); margin-bottom:5px; border-radius:6px; border:1px solid var(--border-color);'><span>" . htmlspecialchars($i['name']) . " <small style='color:var(--text-muted);'>(" . htmlspecialchars($i['method']) . ")</small></span><strong>$" . number_format($amt, 2) . "</strong></li>";
                    }
                    $expHtml .= "</ul></div>";
                }
            }
            
            $subsHtml = "";
            if (!empty($bData['yearly_subs'])) {
                $subsHtml = "<h3 style='border-bottom:1px solid var(--border-color); padding-bottom:5px; color:#eab308; margin-top:30px;'>Yearly Subscriptions <small style='font-size:0.7em; color:var(--text-muted);'>(Monthly Set-Aside)</small></h3><ul style='list-style:none; padding:0; margin:0;'>";
                foreach($bData['yearly_subs'] as $sub) {
                    $ann_amt = (float)($sub['annual_amount'] ?? 0);
                    $mo_amt = $ann_amt / 12;
                    $expTotal += $mo_amt;
                    $subsHtml .= "<li style='display:flex; justify-content:space-between; padding:8px; background:var(--bg-color); margin-bottom:5px; border-radius:6px; border:1px solid var(--border-color);'><span>" . htmlspecialchars($sub['name']) . " <small style='color:var(--text-muted);'>(" . htmlspecialchars($sub['method']) . ")</small></span><span><span style='color:var(--text-muted); font-size:0.85em; margin-right:10px;'>$" . number_format($ann_amt, 2) . "/yr</span><strong>$" . number_format($mo_amt, 2) . "/mo</strong></span></li>";
                }
                $subsHtml .= "</ul>";
            }
            
            $remaining = $incTotal - $expTotal;
            $remColor = $remaining === 0 ? '#22c55e' : ($remaining > 0 ? '#eab308' : '#ef4444');
            
            $summaryHtml = "
            <div style='display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px;'>
                <div style='background:var(--bg-color); border: 1px solid var(--border-color); border-top: 3px solid #22c55e; padding: 15px; border-radius: 8px;'>
                    <div style='font-size: 0.8em; color: var(--text-muted); text-transform: uppercase;'>Expected Income</div>
                    <div style='font-size: 1.6em; font-weight: bold; color: var(--text-main);'>$" . number_format($incTotal,2) . "</div>
                </div>
                <div style='background:var(--bg-color); border: 1px solid var(--border-color); border-top: 3px solid #ef4444; padding: 15px; border-radius: 8px;'>
                    <div style='font-size: 0.8em; color: var(--text-muted); text-transform: uppercase;'>Assigned Dollars</div>
                    <div style='font-size: 1.6em; font-weight: bold; color: var(--text-main);'>$" . number_format($expTotal,2) . "</div>
                </div>
                <div style='background:var(--bg-color); border: 1px solid var(--border-color); border-top: 3px solid {$remColor}; padding: 15px; border-radius: 8px;'>
                    <div style='font-size: 0.8em; color: var(--text-muted); text-transform: uppercase;'>Unallocated Funds</div>
                    <div style='font-size: 1.6em; font-weight: bold; color: {$remColor};'>$" . number_format(abs($remaining),2) . "</div>
                </div>
            </div>";
            
            $data['content'] = $summaryHtml . $incHtml . $expHtml . $subsHtml;
        }
    } elseif ($type === 'ledger') {
        $stmt = $pdo->prepare("SELECT ledger_year, ledger_data, updated_at as created_at FROM yearly_ledgers WHERE share_token = :t");
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $data = [
                'title' => "Annual Ledger - {$row['ledger_year']}",
                'created_at' => $row['created_at'],
                'category' => 'Financial',
                'status' => ''
            ];
            
            $lData = json_decode($row['ledger_data'], true);
            $contentHtml = "<div style='padding:15px; background:var(--bg-color); border:1px solid var(--border-color); border-radius:8px; margin-bottom:20px; font-size:1.1em;'><strong>Annual Start Balance:</strong> $" . number_format((float)($lData['annual_start'] ?? 0), 2) . "</div>";
            
            $contentHtml .= "<style>
                .ledger-tbl { width: 100%; border-collapse: collapse; margin-bottom: 30px; font-size: 0.9em; }
                .ledger-tbl th, .ledger-tbl td { border: 1px solid var(--border-color); padding: 10px; text-align: left; }
                .ledger-tbl th { background: var(--bg-color); color: var(--text-muted); font-weight: bold; text-transform: uppercase; font-size: 0.85em; }
                .ledger-tbl td.amt { text-align: right; font-family: monospace; font-size: 1.1em; }
                .ledger-tbl tr:nth-child(even) { background: rgba(255,255,255,0.02); }
            </style>";
            
            if (!empty($lData['months'])) {
                foreach(['Jan','Feb','Mar','Apr','May','June','July','Aug','Sept','Oct','Nov','Dec'] as $m) {
                    if (!empty($lData['months'][$m])) {
                        // Filter out completely empty rows that have no amount or item
                        $validRows = array_filter($lData['months'][$m], function($r) {
                            return (!empty($r['item']) || (isset($r['amount']) && $r['amount'] !== ''));
                        });
                        
                        if (count($validRows) > 0) {
                            $contentHtml .= "<h3 style='color:var(--accent); border-bottom:1px solid var(--border-color); padding-bottom:5px;'>{$m} {$row['ledger_year']}</h3>";
                            $contentHtml .= "<div style='overflow-x:auto;'><table class='ledger-tbl'><thead><tr>
                                <th>Date</th><th>Item</th><th>Category/Income</th><th>Method</th><th style='text-align:right;'>Amount</th><th style='text-align:right;'>Total</th>
                            </tr></thead><tbody>";
                            
                            foreach($validRows as $vr) {
                                $amt = $vr['amount'] ?? '';
                                $tot = $vr['total'] ?? '';
                                $amtStr = is_numeric($amt) ? "$" . number_format((float)$amt, 2) : '';
                                $totStr = is_numeric($tot) ? "$" . number_format((float)$tot, 2) : '';
                                
                                $amtColor = is_numeric($amt) && $amt < 0 ? '#ef4444' : (is_numeric($amt) && $amt > 0 ? '#22c55e' : 'inherit');
                                
                                $contentHtml .= "<tr>
                                    <td>" . htmlspecialchars($vr['date'] ?? '') . "</td>
                                    <td><strong>" . htmlspecialchars($vr['item'] ?? '') . "</strong></td>
                                    <td>" . htmlspecialchars($vr['category'] ?? '') . "</td>
                                    <td>" . htmlspecialchars($vr['method'] ?? '') . "</td>
                                    <td class='amt' style='color:{$amtColor};'>{$amtStr}</td>
                                    <td class='amt' style='font-weight:bold;'>{$totStr}</td>
                                </tr>";
                            }
                            $contentHtml .= "</tbody></table></div>";
                        }
                    }
                }
            }
            $data['content'] = $contentHtml;
        }
    }
} catch (Exception $e) {
    die("Database error.");
}

if (!$data) {
    die("This shared link has expired or does not exist.");
}

$is_rich_html = in_array($type, ['note', 'kb', 'budget', 'ledger']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($data['title']) ?> - Shared via MemoMatrix</title>
    <!-- Include Quill CSS to perfectly render rich text -->
    <link href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0f172a; --card-bg: #1e293b; --text-main: #f8fafc; --text-muted: #94a3b8;
            --accent: #3b82f6; --border-color: #334155;
        }
        body { font-family: 'Inter', system-ui, sans-serif; background-color: var(--bg-color); color: var(--text-main); margin: 0; padding: 40px 20px; line-height: 1.6; }
        .container { max-width: 800px; margin: 0 auto; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .header { padding: 30px; border-bottom: 1px solid var(--border-color); }
        .meta { display: flex; gap: 15px; font-size: 0.85em; color: var(--text-muted); margin-bottom: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .title { margin: 0; font-size: 2em; color: var(--text-main); }
        .content { padding: 30px; font-size: 1.05em; }
        .footer { padding: 15px 30px; background: rgba(59, 130, 246, 0.05); text-align: center; border-top: 1px solid var(--border-color); font-size: 0.8em; color: var(--text-muted); }
        
        /* Quill Read-Only Overrides */
        .ql-snow .ql-editor { padding: 0; }
        .ql-editor { font-family: inherit; font-size: inherit; color: inherit; }
        .tag-pill { background: rgba(59, 130, 246, 0.15); color: var(--accent); padding: 4px 12px; border-radius: 20px; font-size: 0.9em; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="meta">
                <span>Shared <?= ucfirst($type) ?></span>
                <?php if (!empty($data['category'])): ?>
                    <span class="tag-pill"><?= htmlspecialchars($data['category']) ?></span>
                <?php endif; ?>
                <?php if (!empty($data['status'])): ?>
                    <span style="color: <?= $data['status'] === 'completed' ? '#22c55e' : '#eab308' ?>;">• <?= ucfirst(str_replace('_', ' ', $data['status'])) ?></span>
                <?php endif; ?>
            </div>
            <h1 class="title"><?= htmlspecialchars($data['title']) ?></h1>
            <div style="margin-top: 10px; color: var(--text-muted); font-size: 0.9em;">
                <?= $type === 'meeting' ? 'Scheduled for: ' : 'Date: ' ?> 
                <?= $data['created_at'] ? date('l, F j, Y', strtotime($data['created_at'])) : 'N/A' ?>
            </div>
        </div>
        
        <div class="content ql-snow">
            <div class="ql-editor">
                <?= $is_rich_html ? $data['content'] : nl2br(htmlspecialchars($data['content'] ?: 'No additional details provided.')) ?>
            </div>
        </div>
        
        <div class="footer">Securely shared via MemoMatrix</div>
    </div>
</body>
</html>