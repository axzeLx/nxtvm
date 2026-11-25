{{-- Status dot. $level: ok | warn | crit | info | unknown --}}
<span @class(['dot', 'bg-ok' => $level === 'ok', 'bg-warn' => $level === 'warn', 'bg-crit' => $level === 'crit', 'bg-info' => $level === 'info', 'bg-unknown' => ! in_array($level, ['ok', 'warn', 'crit', 'info'])])
      title="{{ $title ?? '' }}"></span>
