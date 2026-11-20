<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request a call</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; margin: 0; padding: 16px; background: #f8fafc; }
        .card { max-width: 420px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
        h1 { font-size: 20px; margin: 0 0 4px; color: #0f172a; }
        p.sub { font-size: 14px; color: #64748b; margin: 0 0 16px; }
        label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin: 12px 0 4px; }
        input, textarea { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
        input:focus, textarea:focus { outline: 2px solid #6366f1; border-color: #6366f1; }
        button { width: 100%; margin-top: 16px; padding: 12px; background: #6366f1; color: #fff; border: 0; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; }
        button:hover { background: #4f46e5; }
        .success { background: #dcfce7; color: #166534; border-radius: 8px; padding: 12px; font-size: 14px; }
        .hidden { display: none; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Request a call</h1>
        <p class="sub">Leave your details and our team will contact you shortly.</p>

        @if($submitted)
            <div class="success">Thank you! Your request was received — we will call you soon.</div>
        @else
            <form method="POST" action="{{ url('/api/leads') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $integration->token }}">
                <label for="name">Full name *</label>
                <input id="name" name="name" type="text" required maxlength="255">
                <label for="phone">Phone *</label>
                <input id="phone" name="phone" type="tel" required maxlength="32">
                <label for="email">Email (optional)</label>
                <input id="email" name="email" type="email" maxlength="255">
                <label for="notes">What are you interested in? (optional)</label>
                <textarea id="notes" name="notes" rows="3" maxlength="2000"></textarea>
                <button type="submit">Send request</button>
            </form>
        @endif
    </div>
</body>
</html>
