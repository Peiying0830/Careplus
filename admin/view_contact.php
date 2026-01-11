<?php
require_once '../config.php';
requireRole('admin');

// Get the MySQLi connection from your singleton
$conn = Database::getInstance()->getConnection();

// Execute the query using MySQLi query() method
$result = $conn->query("
    SELECT * FROM contact_submissions 
    ORDER BY created_at DESC
");

// Fetch all results as an associative array (MySQLi equivalent of fetchAll)
$submissions = [];
if ($result) {
    $submissions = $result->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Submissions - Admin</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; padding: 20px; background: #f4f7f6; }
        h1 { color: #1e293b; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; background: white; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        th, td { padding: 12px 15px; border: 1px solid #e2e8f0; text-align: left; }
        th { background: #2563eb; color: white; text-transform: uppercase; font-size: 0.85rem; letter-spacing: 0.05em; }
        
        /* Status Row Colors */
        tr.new { background: #fffbeb; } /* Light amber for new items */
        tr.read { background: #f8fafc; }
        tr.replied { background: #f0fdf4; }
        
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: bold; }
        .badge-new { background: #fbbf24; color: #78350f; }
        .badge-read { background: #94a3b8; color: white; }
        .badge-replied { background: #22c55e; color: white; }
        
        a { color: #2563eb; text-decoration: none; font-weight: 600; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <h1>Contact Form Submissions</h1>
    
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Date</th>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Subject</th>
                <th>Message</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($submissions)): ?>
                <?php foreach ($submissions as $sub): ?>
                    <tr class="<?php echo $sub['status']; ?>">
                        <td><code>#<?php echo $sub['id']; ?></code></td>
                        <td><?php echo date('M d, Y h:i A', strtotime($sub['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars($sub['name']); ?></td>
                        <td><a href="mailto:<?php echo $sub['email']; ?>"><?php echo htmlspecialchars($sub['email']); ?></a></td>
                        <td><?php echo htmlspecialchars($sub['phone'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($sub['subject']); ?></td>
                        <td style="max-width: 300px; font-size: 0.9rem;"><?php echo nl2br(htmlspecialchars($sub['message'])); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $sub['status']; ?>">
                                <?php echo strtoupper($sub['status']); ?>
                            </span>
                        </td>
                        <td>
                            <a href="mailto:<?php echo $sub['email']; ?>">Reply</a> |
                            <a href="update_contact_status.php?id=<?php echo $sub['id']; ?>&status=replied" 
                               onclick="return confirm('Mark this as replied?')">Mark Replied</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9" style="text-align: center; padding: 40px; color: #64748b;">No contact submissions found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>