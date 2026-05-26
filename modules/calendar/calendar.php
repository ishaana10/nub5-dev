<?php
require_once '../../config.php';
require_once '../../core/Database.php';
require_once '../../core/Auth.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) exit('Unauthorized');

$db = NuDatabase::getInstance();
$events = $db->fetchAll("SELECT * FROM nu_calendar_events WHERE event_user_id = :user OR event_user_id IS NULL ORDER BY event_start", 
    [':user' => $_SESSION['nu_user_id']]);
?>

<div class="nu-calendar">
    <div class="nu-card" style="margin-bottom: 24px;">
        <div class="nu-card-header">
            <h3 class="nu-card-title">Calendar & Scheduler</h3>
            <button class="nu-btn nu-btn-primary" onclick="openEventModal()">+ New Event</button>
        </div>
        <div id="calendarView" style="background:var(--bg-elevated);border-radius:var(--radius-lg);padding:20px;min-height:400px;">
            <div class="nu-calendar-grid" style="display:grid;grid-template-columns:repeat(7, 1fr);gap:8px;">
                <div style="font-weight:600;font-size:12px;text-transform:uppercase;color:var(--text-tertiary);text-align:center;padding:8px;">Sun</div>
                <div style="font-weight:600;font-size:12px;text-transform:uppercase;color:var(--text-tertiary);text-align:center;padding:8px;">Mon</div>
                <div style="font-weight:600;font-size:12px;text-transform:uppercase;color:var(--text-tertiary);text-align:center;padding:8px;">Tue</div>
                <div style="font-weight:600;font-size:12px;text-transform:uppercase;color:var(--text-tertiary);text-align:center;padding:8px;">Wed</div>
                <div style="font-weight:600;font-size:12px;text-transform:uppercase;color:var(--text-tertiary);text-align:center;padding:8px;">Thu</div>
                <div style="font-weight:600;font-size:12px;text-transform:uppercase;color:var(--text-tertiary);text-align:center;padding:8px;">Fri</div>
                <div style="font-weight:600;font-size:12px;text-transform:uppercase;color:var(--text-tertiary);text-align:center;padding:8px;">Sat</div>
                <?php
                $today = new DateTime();
                $firstDay = new DateTime($today->format('Y-m-01'));
                $daysInMonth = (int)$today->format('t');
                $startOffset = (int)$firstDay->format('w');

                // Empty cells before start
                for ($i = 0; $i < $startOffset; $i++) {
                    echo '<div style="min-height:80px;background:var(--bg-secondary);border-radius:var(--radius-sm);"></div>';
                }

                // Day cells
                for ($day = 1; $day <= $daysInMonth; $day++) {
                    $isToday = $day == (int)$today->format('j');
                    $cellStyle = $isToday ? 'background:var(--accent);color:#fff;' : 'background:var(--bg-secondary);';
                    echo '<div style="min-height:80px;border-radius:var(--radius-sm);padding:8px;' . $cellStyle . '">';
                    echo '<div style="font-weight:600;font-size:14px;margin-bottom:4px;">' . $day . '</div>';

                    foreach ($events as $e) {
                        $eventDate = new DateTime($e['event_start']);
                        if ((int)$eventDate->format('j') == $day && $eventDate->format('Y-m') == $today->format('Y-m')) {
                            $color = $e['event_color'] ?? 'var(--accent)';
                            echo '<div style="font-size:11px;padding:2px 6px;background:' . $color . ';color:#fff;border-radius:var(--radius-sm);margin-bottom:2px;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" onclick="viewEvent(' . $e['event_id'] . ')">' . htmlspecialchars($e['event_title']) . '</div>';
                        }
                    }

                    echo '</div>';
                }
                ?>
            </div>
        </div>
    </div>

    <!-- Upcoming Events List -->
    <div class="nu-card">
        <div class="nu-card-header">
            <h3 class="nu-card-title">Upcoming Events</h3>
        </div>
        <div class="nu-table-wrap">
            <table class="nu-table">
                <thead>
                    <tr><th>Title</th><th>Start</th><th>End</th><th>Type</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $e): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($e['event_title']); ?></strong></td>
                        <td><?php echo date('M j, Y g:i A', strtotime($e['event_start'])); ?></td>
                        <td><?php echo $e['event_end'] ? date('M j, Y g:i A', strtotime($e['event_end'])) : '-'; ?></td>
                        <td><span class="nu-status nu-status-active"><?php echo ucfirst($e['event_type'] ?? 'event'); ?></span></td>
                        <td>
                            <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="editEvent(<?php echo $e['event_id']; ?>)">Edit</button>
                            <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="deleteEvent(<?php echo $e['event_id']; ?>)">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($events)): ?>
                    <tr><td colspan="5" style="text-align:center;color:var(--text-tertiary);">No upcoming events.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="nu-modal-overlay" id="eventModal">
    <div class="nu-modal">
        <div class="nu-modal-header">
            <h3 class="nu-modal-title">Event</h3>
            <button class="nu-modal-close" onclick="closeEventModal()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="nu-modal-body">
            <div class="nu-field">
                <label>Title</label>
                <input type="text" class="nu-input" id="eventTitle" placeholder="Team Meeting">
            </div>
            <div class="nu-field">
                <label>Description</label>
                <textarea class="nu-input" id="eventDescription" rows="2"></textarea>
            </div>
            <div class="nu-form-grid" style="grid-template-columns:1fr 1fr;">
                <div class="nu-field">
                    <label>Start</label>
                    <input type="datetime-local" class="nu-input" id="eventStart">
                </div>
                <div class="nu-field">
                    <label>End</label>
                    <input type="datetime-local" class="nu-input" id="eventEnd">
                </div>
            </div>
            <div class="nu-field">
                <label>Event Type</label>
                <select class="nu-input" id="eventType">
                    <option value="meeting">Meeting</option>
                    <option value="task">Task</option>
                    <option value="reminder">Reminder</option>
                    <option value="deadline">Deadline</option>
                </select>
            </div>
            <div class="nu-field">
                <label>Color</label>
                <input type="color" class="nu-input" id="eventColor" value="#0ea5e9" style="height:40px;padding:4px;">
            </div>
        </div>
        <div class="nu-modal-footer">
            <button class="nu-btn nu-btn-ghost" onclick="closeEventModal()">Cancel</button>
            <button class="nu-btn nu-btn-primary" onclick="saveEvent()">Save</button>
        </div>
    </div>
</div>


