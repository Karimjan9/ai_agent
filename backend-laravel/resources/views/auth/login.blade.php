<!doctype html>
<html lang="uz"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>NeuroTrader Login</title><style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#17231d;font:15px system-ui;color:#16201b}.card{width:min(420px,calc(100% - 32px));background:#fff;padding:30px;border-radius:12px}.brand{font-size:24px;font-weight:800;margin-bottom:6px}.muted{color:#65736b;margin-bottom:24px}label{display:grid;gap:7px;margin:14px 0;font-weight:700}input{padding:12px;border:1px solid #dce3dd;border-radius:8px;font:inherit}button{width:100%;padding:12px;border:0;border-radius:8px;background:#247a7a;color:#fff;font-weight:800}.error{color:#ad3e45}
</style></head><body><form class="card" method="POST" action="{{ route('login.store') }}">@csrf
<div class="brand">NeuroTrader Lab</div><div class="muted">Himoyalangan operator paneli</div>
@error('email')<div class="error">{{ $message }}</div>@enderror
<label>Email<input type="email" name="email" value="{{ old('email') }}" required autofocus></label>
<label>Parol<input type="password" name="password" required></label>
<label style="display:flex"><input type="checkbox" name="remember" value="1"> Eslab qolish</label>
<button type="submit">Kirish</button></form></body></html>
