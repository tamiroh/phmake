#!/usr/bin/perl

use File::Temp qw(tempdir);
use Test::More;

require '/opt/make/tests/test_driver.pl';
$osname = 'GNU/Linux';
$port_type = 'UNIX';
$test_timeout = 1;

is(_run_with_timeout($^X, '-e', 'exit 7'), 7 << 8, 'preserve command exit status');

my $directory = tempdir(CLEANUP => 1);
my $pidfile = "$directory/pids";
my $child = q{
    $SIG{ALRM} = 'IGNORE';
    $SIG{TERM} = 'IGNORE';
    my $pid = fork();
    defined $pid or die "fork: $!";
    if ($pid) {
        open my $fh, '>', $ARGV[0] or die "open: $!";
        print $fh "$$ $pid\n";
        close $fh;
    }
    sleep 30;
};
eval { _run_with_timeout($^X, '-e', $child, $pidfile); };
is($@, "timeout\n", 'report timeout even when commands ignore signals');
open my $fh, '<', $pidfile or die "open: $!";
my @pids = split /\s+/, <$fh>;
close $fh;
is(scalar @pids, 2, 'exercise both child and grandchild');
is(waitpid($pids[0], 1), -1, 'reap direct child before returning');
for my $pid (@pids) {
    # An orphan can briefly be a zombie until the container init reaps it.
    my $state = '';
    if (open my $stat, '<', "/proc/$pid/stat") {
        $state = <$stat>;
        close $stat;
    }
    ok($state eq '' || $state =~ /\) Z /, "process $pid cannot continue running");
}
is(_run_with_timeout($^X, '-e', 'exit 0'), 0, 'continue with the next command');
for my $mode (0, 1) {
    local $phmake = $mode;
    local *run_make_test = sub {
        my ($makefile, $flags, $expected) = @_;
        is($flags, '-d --trace', 'retain the combined debug options');
        my $pattern = substr($expected, 1, -1);
        my $identity = $mode ? 'phmake (development)' : 'GNU Make 4.4.1';
        like($identity, qr/$pattern/, 'accept the selected executable identity');
        unlike('', qr/$pattern/, 'reject missing debug banner');
        unlike('unrelated program', qr/$pattern/, 'reject unrelated program identity');
    };
    do '/opt/make/tests/scripts/options/dash-d' or die $@ || $!;
}
done_testing();
