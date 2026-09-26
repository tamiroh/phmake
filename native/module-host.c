#define _POSIX_C_SOURCE 200809L
#include <dlfcn.h>
#include <errno.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include "module-api.h"

#ifdef PHMAKE_GUILE
extern char *phmake_guile(const char *, int *);
#endif

/* Only the native ABI lives here. Expansion and evaluation are callbacks to PHP. */
typedef struct function_entry {
    char *name;
    gmk_func function;
    struct function_entry *next;
} function_entry;
typedef struct { char kind; uint32_t count; char **values; } message;

static int protocol;
static FILE *captured_out, *captured_err;
static long out_offset, err_offset;
static function_entry *functions;

static void transfer(int fd, void *data, size_t size, int writing)
{
    char *bytes = data;
    while (size) {
        ssize_t n = writing ? write(fd, bytes, size) : read(fd, bytes, size);
        if (n < 0 && errno == EINTR) continue;
        if (n <= 0) exit(2);
        bytes += n;
        size -= (size_t)n;
    }
}

static void number(int fd, uint32_t value)
{
    unsigned char bytes[] = {value >> 24, value >> 16, value >> 8, value};
    transfer(fd, bytes, sizeof bytes, 1);
}

static uint32_t read_number(void)
{
    unsigned char b[4];
    transfer(STDIN_FILENO, b, sizeof b, 0);
    return (uint32_t)b[0] << 24 | (uint32_t)b[1] << 16 | (uint32_t)b[2] << 8 | b[3];
}

static void raw_message(char kind, uint32_t count, const char *const *values)
{
    transfer(protocol, &kind, 1, 1);
    number(protocol, count);
    for (uint32_t i = 0; i < count; ++i) {
        size_t length = strlen(values[i]);
        if (length > UINT32_MAX) exit(2);
        number(protocol, (uint32_t)length);
        transfer(protocol, (void *)values[i], length, 1);
    }
}

static void flush_capture(FILE *stream, long *offset, char kind)
{
    char bytes[4096];
    int fd = fileno(stream);
    ssize_t n;
    while ((n = pread(fd, bytes, sizeof bytes, *offset)) > 0) {
        transfer(protocol, &kind, 1, 1);
        number(protocol, 1);
        number(protocol, (uint32_t)n);
        transfer(protocol, bytes, (size_t)n, 1);
        *offset += n;
    }
}

static void send_message(char kind, uint32_t count, const char *const *values)
{
    fflush(stdout);
    fflush(stderr);
    flush_capture(captured_out, &out_offset, 'O');
    flush_capture(captured_err, &err_offset, 'S');
    raw_message(kind, count, values);
}

static message receive(char kind)
{
    message result;
    result.kind = kind;
    if (!kind) transfer(STDIN_FILENO, &result.kind, 1, 0);
    result.count = read_number();
    if (result.count > 65536) exit(2);
    result.values = calloc((size_t)result.count + 1, sizeof *result.values);
    if (!result.values) exit(2);
    for (uint32_t i = 0; i < result.count; ++i) {
        uint32_t length = read_number();
        if (length > 64 * 1024 * 1024) exit(2);
        result.values[i] = malloc((size_t)length + 1);
        if (!result.values[i]) exit(2);
        transfer(STDIN_FILENO, result.values[i], length, 0);
        result.values[i][length] = '\0';
    }
    return result;
}

static void dispose(message *value)
{
    for (uint32_t i = 0; i < value->count; ++i) free(value->values[i]);
    free(value->values);
}

static void dispatch(message *request);

static char *callback(char kind, uint32_t count, const char *const *values)
{
    send_message(kind, count, values);
    for (;;) {
        message response = receive(0);
        if (response.kind == 'H' && response.count == 1) {
            char *result = response.values[0];
            free(response.values);
            return result;
        }
        /* Expansion can call another loaded function before returning. */
        dispatch(&response);
        dispose(&response);
    }
}

char *gmk_alloc(unsigned int size) { return malloc(size); }
void gmk_free(void *memory) { free(memory); }

char *gmk_expand(const char *expression)
{
    const char *values[] = {expression};
    return callback('V', 1, values);
}

void gmk_eval(const char *text, const gmk_floc *location)
{
    char line[32];
    snprintf(line, sizeof line, "%lu", location ? location->lineno : 0);
    const char *values[] = {text, location && location->filenm ? location->filenm : "", line};
    free(callback('A', 3, values));
}

void gmk_add_function(const char *name, gmk_func function,
                      unsigned int minimum, unsigned int maximum, unsigned int flags)
{
    function_entry *entry = malloc(sizeof *entry);
    if (!entry) exit(2);
    entry->name = strdup(name);
    entry->function = function;
    entry->next = functions;
    functions = entry;
    char min[32], max[32], mode[32];
    snprintf(min, sizeof min, "%u", minimum);
    snprintf(max, sizeof max, "%u", maximum);
    snprintf(mode, sizeof mode, "%u", flags);
    const char *values[] = {name, min, max, mode};
    send_message('F', 4, values);
}

static void dispatch(message *request)
{
    const char *result = "";
    char status[32];
    if (request->kind == 'P' && request->count == 0) {
#ifdef PHMAKE_GUILE
        result = "load guile";
#else
        result = "load";
#endif
#ifdef PHMAKE_GUILE
    } else if (request->kind == 'G' && request->count == 1) {
        int failed = 0;
        char *value = phmake_guile(request->values[0], &failed);
        const char *values[] = {value ? value : ""};
        send_message(failed ? 'E' : 'R', 1, values);
        free(value);
        return;
#endif
    } else if (request->kind == 'L' && request->count == 4) {
        void *handle = dlopen(request->values[0], RTLD_NOW | RTLD_GLOBAL);
        if (!handle) {
            const char *error[] = {dlerror()};
            send_message('E', 1, error);
            return;
        }
        int (*setup)(const gmk_floc *) = NULL;
        void *symbol = dlsym(handle, request->values[1]);
        memcpy(&setup, &symbol, sizeof setup);
        if (!dlsym(handle, "plugin_is_GPL_compatible") || !setup) {
            const char *error[] = {"Missing plugin compatibility declaration or setup function"};
            send_message('E', 1, error);
            dlclose(handle);
            return;
        }
        gmk_floc location = {request->values[2], strtoul(request->values[3], NULL, 10)};
        snprintf(status, sizeof status, "%d", setup(&location));
        result = status;
    } else if (request->kind == 'C' && request->count >= 1) {
        function_entry *entry = functions;
        while (entry && strcmp(entry->name, request->values[0])) entry = entry->next;
        if (!entry) {
            const char *error[] = {"Unknown loaded function"};
            send_message('E', 1, error);
            return;
        }
        char *value = entry->function(request->values[0], request->count - 1, request->values + 1);
        const char *values[] = {value ? value : ""};
        send_message('R', 1, values);
        free(value);
        return;
    } else {
        const char *error[] = {"Invalid module host request"};
        send_message('E', 1, error);
        return;
    }
    const char *values[] = {result};
    send_message('R', 1, values);
}

int main(void)
{
    protocol = dup(STDOUT_FILENO);
    captured_out = tmpfile();
    captured_err = tmpfile();
    if (protocol < 0 || !captured_out || !captured_err
        || dup2(fileno(captured_out), STDOUT_FILENO) < 0
        || dup2(fileno(captured_err), STDERR_FILENO) < 0) return 2;
    for (;;) {
        char next;
        ssize_t count = read(STDIN_FILENO, &next, 1);
        if (count < 0 && errno == EINTR) continue;
        if (count <= 0) return count == 0 ? 0 : 2;
        if (next != 'L' && next != 'C' && next != 'P' && next != 'G') return 2;
        message request = receive(next);
        dispatch(&request);
        dispose(&request);
    }
}
